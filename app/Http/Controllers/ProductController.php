<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;
use League\Csv\Reader;

class ProductController extends Controller
{


    //save to database
    public function getProducts()
    {
        ini_set('memory_limit', '2000M');
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        try {
            $baseUrl = env('BASE_URL');

            // Get Session ID
            $sessionResponse = Http::get($baseUrl . '/Connect');
            if (!$sessionResponse->successful()) {
                return response()->json(['error' => 'Unable to connect to IntegratorService'], 500);
            }
            $sessionId = $sessionResponse['SessionID'] ?? null;
            if (!$sessionId) {
                return response()->json(['error' => 'Session ID missing'], 500);
            }

            // Get products using session ID
            $runProcUrl = "$baseUrl/(S($sessionId))/RunProc";
            $payload = ['ProcName' => 'Common_Product'];
            $runProcResponse = Http::post($runProcUrl, $payload);
            if (!$runProcResponse->successful()) {
                return response()->json(['error' => 'Failed to execute procedure'], 500);
            }

            $data = $runProcResponse->json();

            foreach ($data as $product) {
                $variantSku = $product['VariantSKU'];

                // Check if variant exists
                $existing = DB::table('shopify_products')
                    ->where('variant_sku', $variantSku)
                    ->first();

                if ($existing) {
                    // Update existing record
                    DB::table('shopify_products')
                        ->where('variant_sku', $variantSku)
                        ->update([
                            'item_code' => $product['ItemCode'],
                            'barcode' => $product['Barcode'],
                            'raw_data' => json_encode($product),
                            'updated_at' => now(),
                        ]);
                } else {
                    // Insert new variant
                    DB::table('shopify_products')->insert([
                        'item_code' => $product['ItemCode'],
                        'barcode' => $product['Barcode'],
                        'variant_sku' => $variantSku,
                        'raw_data' => json_encode($product),
                        'status' => 'pending',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            return response()->json(['message' => 'Products synced successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    //sync to shopify
    public function syncProduct()
    {
        ini_set('max_execution_time', 0);
        $products = DB::table('shopify_products')
            ->where('item_code', 'CENC024')
            ->get();

        $groupedProducts = $products->groupBy(function ($product) {
            $data = json_decode($product->raw_data, true);
            return $data['ItemCode'];
        });

        foreach ($groupedProducts as $itemCode => $productGroup) {
            try {
                $firstProduct = json_decode($productGroup->first()->raw_data, true);

                $shopifyProductId = $productGroup->first()->shopify_product_id;

                $isCreated = $productGroup->first()->is_created;

                // Get or create product
                if (!$shopifyProductId) {

                    $productId = $this->createShopifyProduct($firstProduct, $productGroup);

                    DB::table('shopify_products')
                        ->where('item_code', $itemCode)
                        ->update([
                            'shopify_product_id' => $productId,
                            'is_created' => true,
                            'handle' => $itemCode,
                            'status' => 'completed',
                            'updated_at' => now()
                        ]);
                } else {

                    $this->updateShopifyProduct($shopifyProductId, $productGroup, $isCreated);
                    DB::table('shopify_products')
                        ->where('item_code', $itemCode)
                        ->update(['status' => 'completed', 'updated_at' => now()]);
                }
            } catch (\Exception $e) {
                // Error handling
            }
        }
    }

    private function createShopifyProduct(array $productData, $productGroup): string
    {

        $descriptionHtml = $this->formatDescription($productData);


        $variantDataArray = $productGroup->map(function ($item) {
            return json_decode($item->raw_data, true);
        })->toArray();




        $mutation = <<<GQL
                mutation {
                    productCreate(input: {
                        title: "{$productData['ItemCode']}",
                        descriptionHtml: "{$descriptionHtml}",
                        productType: "{$productData['ProductAtt01Desc']}",
                        status: DRAFT,
                        handle: "{$productData['ItemCode']}",
                        options: ["Size", "Color"],
                        variants: [
                            {$this->formatCreateVariants($variantDataArray)}
                        ]
                    }) {
                        product {
                            id
                            variants(first: 100) {
                                edges {
                                    node {
                                        id
                                        selectedOptions {
                                            name
                                            value
                                        }
                                    }
                                }
                            }
                        }
                        userErrors {
                            field
                            message
                        }
                    }
                }
                GQL;



        $createResponse = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $mutation]);

        $createData = $createResponse->json();

        if (!empty($createData['data']['productCreate']['userErrors'])) {
            throw new Exception(json_encode($createData['data']['productCreate']['userErrors']));
        }
        return  $createData['data']['productCreate']['product']['id'];
    }

    private function formatCreateVariants(array $variants): string
    {
        return implode(',', array_map(function ($variant) {
            $price = number_format((float)$variant['NetPrice'], 2, '.', '');
            $comparePrice = number_format((float)$variant['RegularPrice'], 2, '.', '');

            return <<<VARIANT
            {
                price: "$price",
                compareAtPrice: "$comparePrice",
                sku: "{$variant['VariantSKU']}",
                barcode: "{$variant['Barcode']}",
                options: ["{$variant['ItemDim1Code']}", "{$variant['ColorCode']}"]
            }
            VARIANT;
        }, $variants));
    }

    private function formatDescription($data): string
    {
        $points = array_filter([
            $data['ItemDescription'] ?? '',
            $data['ProductAtt01Desc'] ?? '',
            $data['ProductAtt02Desc'] ?? '',
            $data['ProductAtt03Desc'] ?? '',
            $data['ProductAtt04Desc'] ?? '',
            $data['ProductAtt05Desc'] ?? ''
        ]);
        return '<ul>' . implode('', array_map(fn($point) => "<li>{$point}</li>", $points)) . '</ul>';
    }

    private function updateShopifyProduct(string $productId, $productGroup, bool $isCreated): void
    {
        $existingVariants = $this->getExistingVariants($productId);

        $updates = $this->calculateVariantUpdates($productGroup, $existingVariants);

        if (!empty($updates['create'])) {
            $this->createNewVariants($productId, $updates['create']);
        }

        if (!empty($updates['update'])) {

            $this->updateExistingVariants($productId, $updates['update']);
        }

        if (!$isCreated) {
            $this->updateProductTitleOnce($productId, $productGroup);
        }
    }


    private function calculateVariantUpdates($productGroup, array $existingVariants): array
    {
        $updates = ['create' => [], 'update' => []];

        foreach ($productGroup as $productRecord) {
            $data = json_decode($productRecord->raw_data, true);
            $variantKey = "{$data['ItemDim1Code']}/{$data['ColorCode']}";

            $compareAtPrice = number_format((float)$data['RegularPrice'], 2, '.', '');
            $price = number_format((float)$data['NetPrice'], 2, '.', '');

            if (!isset($existingVariants[$variantKey])) {

                $updates['create'][] = [
                    'sku' => $data['VariantSKU'],
                    'barcode' => $data['Barcode'],
                    'price' => $price,
                    'compareAtPrice' => $compareAtPrice,
                    'options' => [$data['ItemDim1Code'], $data['ColorCode']]
                ];
            } else {

                $existing = $existingVariants[$variantKey];
                //  dd($existing);
                if ($this->shouldUpdatePrice($existing, $price, $compareAtPrice)) {
                    $updates['update'][] = [
                        'id' => $existing['id'],
                        'price' => $price,
                        'compareAtPrice' => $compareAtPrice,
                        'barcode' => $data['Barcode'],
                        'sku' => $data['VariantSKU'],
                    ];
                }
            }
        }

        return $updates;
    }

    private function shouldUpdatePrice(array $existing, string $newPrice, string $newCompareAtPrice): bool
    {
        $currentPrice = (float)$existing['price'];
        $currentCompare = (float)$existing['compareAtPrice'];
        $newPrice = (float)$newPrice;
        $newCompare = (float)$newCompareAtPrice;

        // Only update if new price is lower or compare price changes
        //return ($newPrice < $currentPrice) || ($newCompare != $currentCompare);
        return ($newCompare > 0 && $newPrice < $newCompare) || ($newCompare != $currentCompare);
    }



    private function getExistingVariants(string $productId): array
    {
        $query = <<<GQL
            query {
                product(id: "{$productId}") {
                    variants(first: 100) {
                        edges {
                            node {
                                id
                                sku
                                price
                                compareAtPrice
                                barcode
                                selectedOptions {
                                    name
                                    value
                                }
                            }
                        }
                    }
                }
            }
            GQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $query]);

        $data = $response->json();

        $variants = [];
        foreach ($data['data']['product']['variants']['edges'] ?? [] as $edge) {
            $variant = $edge['node'];
            $options = [
                'Size' => null,
                'Color' => null
            ];

            foreach ($variant['selectedOptions'] as $option) {
                $options[$option['name']] = $option['value'];
            }

            $key = "{$options['Size']}/{$options['Color']}";

            $variants[$key] = [
                'id' => $variant['id'],
                'price' => $variant['price'],
                'compareAtPrice' => $variant['compareAtPrice'],
                'barcode' => $variant['barcode']
            ];
        }

        return $variants;
    }

    private function createNewVariants(string $productId, array $newVariants): void
    {
        $formattedVariants = implode(',', array_map(function ($variant) {
            return <<<VARIANT
        {
            options: ["{$variant['options'][0]}", "{$variant['options'][1]}"],
            sku: "{$variant['sku']}",
            barcode: "{$variant['barcode']}",
            price: "{$variant['price']}",
            compareAtPrice: "{$variant['compareAtPrice']}"
        }
        VARIANT;
        }, $newVariants));

        $mutation = <<<GQL
            mutation {
                productVariantsBulkCreate(
                    productId: "{$productId}",
                    variants: [{$formattedVariants}]
                ) {
                    productVariants {
                        id
                        sku
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $mutation]);

        $result = $response->json();

        if (!empty($result['data']['productVariantsBulkCreate']['userErrors'])) {
            throw new Exception(json_encode($result['data']['productVariantsBulkCreate']['userErrors']));
        }
    }



    private function updateExistingVariants(string $productId, array $updateVariants): void
    {
        $formattedUpdates = implode(',', array_map(function ($variant) {
            return <<<VARIANT
        {
            id: "{$variant['id']}",
            price: "{$variant['price']}",
            compareAtPrice: "{$variant['compareAtPrice']}",
            barcode: "{$variant['barcode']}",
            sku: "{$variant['sku']}",
        }
        VARIANT;
        }, $updateVariants));

        $mutation = <<<GQL
            mutation {
                productVariantsBulkUpdate(
                    productId: "{$productId}",
                    variants: [{$formattedUpdates}]
                ) {
                    productVariants {
                        id
                        sku
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $mutation]);

        $result = $response->json();
        //dd($result);
        if (!empty($result['data']['productVariantsBulkUpdate']['userErrors'])) {
            throw new Exception(json_encode($result['data']['productVariantsBulkUpdate']['userErrors']));
        }
    }

    private function updateProductTitleOnce(string $productId, $productGroup): void
    {
        $firstProduct = json_decode($productGroup->first()->raw_data, true);
        $newTitle = "{$firstProduct['ItemCode']} {$firstProduct['ItemDescription']}";

        $mutation = <<<GQL
            mutation {
                productUpdate(input: {
                    id: "{$productId}",
                    title: "{$newTitle}"
                }) {
                    product {
                        id
                        title
                    }
                    userErrors {
                        field
                        message
                    }
                }
            }
            GQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $mutation]);

        $result = $response->json();

        if (!empty($result['data']['productUpdate']['userErrors'])) {
            throw new Exception(json_encode($result['data']['productUpdate']['userErrors']));
        }
    }



    //map to shopify from nebim

    public function updateSheetOLD()
    {
        try {
            // Get all products with variants from Shopify
            $shopifyVariants = $this->getAllShopifyVariants();


            $updates = [];
            $groupedUpdates = [];
            foreach ($shopifyVariants as $shopifyVariant) {
                // Find matching record in our database by barcode
                $dbRecord = DB::table('shopify_products')
                    ->where('barcode', $shopifyVariant['barcode'])
                    ->first();

                if ($dbRecord) {
                    $nebimData = json_decode($dbRecord->raw_data, true);

                    // Prepare variant update
                    $update = [
                        'id' => $shopifyVariant['id'],
                        'productId' => $shopifyVariant['productId'],
                        'sku' => $nebimData['VariantSKU'],
                        'selectedOptions' => []
                    ];

                    // Find and update Couleur option
                    foreach ($shopifyVariant['selectedOptions'] as $option) {
                        if ($option['name'] === 'Couleur') {
                            $update['selectedOptions'][] = [
                                'name' => 'Couleur',
                                'value' => $nebimData['ColorCode']
                            ];
                        } else {
                            $update['selectedOptions'][] = $option;
                        }
                    }
                    $groupedUpdates[$shopifyVariant['productId']][] = $update;
                    $updates[] = $update;
                }
            }

            // Perform bulk update per productId
            foreach ($groupedUpdates as $productId => $updates) {
                $this->bulkUpdateVariants($productId, $updates);
            }

            // // Perform bulk update
            // if (!empty($updates)) {
            //     dd($updates);
            //     $this->bulkUpdateVariants($updates);
            // }
        } catch (\Exception $e) {
            // Handle error
        }
    }

    private function getAllShopifyVariants(): array
    {

        $query = <<<GQL
        query {
          products(first: 50, sortKey: CREATED_AT, reverse: true) {
                edges {
                    node {
                        id
                        variants(first: 100) {
                            edges {
                                node {
                                    id
                                    sku
                                    barcode
                                    selectedOptions {
                                        name
                                        value
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GQL;


//                 $productId1 = 'gid://shopify/Product/9897725690195';
       
//         $query = <<<GQL
//             query {
//             nodes(ids: ["$productId1"]) {
//                 ... on Product {
//                 id
//                 title
//                 variants(first: 100) {
//                     edges {
//                     node {
//                         id
//                         sku
//                         barcode
//                         selectedOptions {
//                         name
//                         value
//                         }
//                     }
//                     }
//                 }
//                 }
//             }
//             }
// GQL;





        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $query]);

        $data = $response->json();
        //dd($data);
        $variants = [];
        foreach ($data['data']['products']['edges'] ?? [] as $productEdge) {
           // foreach ($data['data']['nodes'] ?? [] as $productEdge) {
              //  dd($productEdge);
            $productId = $productEdge['node']['id'];
           // $productId = $productEdge['id'];

            foreach ($productEdge['node']['variants']['edges'] as $variantEdge) {


                //foreach ($productEdge['variants']['edges'] as $variantEdge) {
                $variants[] = [
                    'id' => $variantEdge['node']['id'],
                    'productId' => $productId,
                    'sku' => $variantEdge['node']['sku'],
                    'barcode' => $variantEdge['node']['barcode'],
                    'selectedOptions' => $variantEdge['node']['selectedOptions']
                ];
            }
        }

        return $variants;
    }

    private function bulkUpdateVariants(string $productId, array $updates): void
    {
        $formatted = implode(',', array_map(function ($update) {
            $optionValues = implode(',', array_map(function ($option) {
                return '{optionName: "' . $option['name'] . '", name: "' . $option['value'] . '"}';
            }, $update['selectedOptions']));

            return <<<VARIANT
            {
                id: "{$update['id']}",
                sku: "{$update['sku']}",
                optionValues: [{$optionValues}]
            }
            VARIANT;
        }, $updates));

        $mutation = <<<GQL
        mutation {
            productVariantsBulkUpdate(
                productId: "{$productId}",
                variants: [{$formatted}]
            ) {
                productVariants {
                    id
                    sku
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GQL;


        //  dd($mutation);

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
            'Content-Type' => 'application/json',
        ])->post(env('GRAPHQL_URL'), ['query' => $mutation]);

        $result = $response->json();
        // dd($result);
        if (!empty($result['data']['productVariantsBulkUpdate']['userErrors'])) {
            throw new \Exception(json_encode($result['data']['productVariantsBulkUpdate']['userErrors']));
        }
    }



    //map to shopify from spreadsheet






    public function updateExist()
    {
        ini_set('max_execution_time', 0);
        try {
            $sheetData = $this->fetchGoogleSheetData();
            
            // Map EAN to color and SKU updates
            $colorMap = collect($sheetData['color_code'])->keyBy('EAN');
            $skuMap = collect($sheetData['sku'])->keyBy('EAN');
           // dd($skuMap);
            $shopifyVariants = $this->getAllShopifyVariants();
            $groupedUpdates = [];

            foreach ($shopifyVariants as $variant) {
                $ean = $variant['barcode'];

                if (!$ean || !isset($colorMap[$ean]) || !isset($skuMap[$ean])) {
                   // dd($ean);
                    continue;
                }
                
                $newColor = $colorMap[$ean]['New Color Code'];
                $newSku = $skuMap[$ean]['New SKU'];

                $update = [
                    'id' => $variant['id'],
                    'productId' => $variant['productId'],
                    'sku' => $newSku,
                    'selectedOptions' => [],
                ];

                foreach ($variant['selectedOptions'] as $option) {
                    $update['selectedOptions'][] = [
                        'name' => $option['name'],
                        'value' => $option['name'] === 'Couleur' ? $newColor : $option['value'],
                    ];
                }

                $groupedUpdates[$variant['productId']][] = $update;
            }

            foreach ($groupedUpdates as $productId => $updates) {
                $this->bulkUpdateVariants($productId, $updates);
            }
        } catch (\Exception $e) {
            Log::error('Update failed: ' . $e->getMessage());
        }
    }




    private function fetchGoogleSheetData()
    {
        $sheets = [
            'color_code' => [
                'gid' => '0#gid=0',
                'columns' => ['EAN', 'Old Color Code', 'New Color Code']
            ],
            'sku' => [
                'gid' => '482842344#gid=482842344',
                'columns' => ['EAN', 'Old SKU', 'New SKU']
            ]
        ];

        $data = [];

        foreach ($sheets as $key => $config) {
            $url = "https://docs.google.com/spreadsheets/d/1bOlbPuGjhlnvQIJg3GPyK69RHlXR-84D6ODRqkWYblk/export?format=csv&gid={$config['gid']}";
            $response = Http::get($url);

            if ($response->successful()) {
                $csv = Reader::createFromString($response->body());
                $csv->setHeaderOffset(0);

                // Handle missing columns in rows
                $header = $csv->getHeader();
                $records = [];

                foreach ($csv->getRecords() as $record) {
                    $records[] = array_pad($record, count($header), '');
                }

                $data[$key] = $records;
            } else {
                throw new \Exception("Failed to fetch $key sheet. Status: {$response->status()}");
            }
        }

        return $data;
    }
}
