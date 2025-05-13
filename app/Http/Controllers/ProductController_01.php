<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Exception;

class ProductController extends Controller
{
    public function getProducts()
    {
        ini_set('memory_limit', '2000M');
        error_reporting(E_ALL);
        ini_set('display_errors', 1);

        try {

            $baseUrl =  env('BASE_URL');

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

            $payload = [
                'ProcName' => 'Common_Product'
            ];

            $runProcResponse = Http::post($runProcUrl, $payload);


            if (!$runProcResponse->successful()) {
                return response()->json(['error' => 'Failed to execute procedure'], 500);
            }
            $data = $runProcResponse->json();

            foreach ($data as $product) {
                DB::table('shopify_products')->insert([
                    'item_code' => $product['ItemCode'],
                    'raw_data' => json_encode($product),
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            return response()->json($runProcResponse->json());
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function syncProduct()
    {
        ini_set('max_execution_time', 0);
        $products = DB::table('shopify_products')
            ->where('status', 'pending')
            // ->where('item_code', 'BHNJ535')
            ->get();

        $groupedProducts = $products->groupBy(function ($product) {
            $data = json_decode($product->raw_data, true);
            return $data['ItemCode'];
        });
        $get_ID = '';
        foreach ($groupedProducts as $itemCode => $productGroup) {
            try {
                $firstProduct = json_decode($productGroup->first()->raw_data, true);
                $descriptionHtml = $this->formatDescription($firstProduct);
                $title = "{$firstProduct['ItemCode']} {$firstProduct['ItemDescription']}";
                $titleCheck = "{$firstProduct['ItemCode']} }";

                // Check if product exists dynamically by title
                $checkQuery = <<<GQL
                    query {
                        products(first: 1, query: "title:{$titleCheck}") {
                            edges {
                                node {
                                    id
                                    title
                                    options {
                                        name
                                        values
                                    }
                                    variants(first: 250) {
                                        edges {
                                            node {
                                                id
                                                barcode
                                                price
                                                optionValues: selectedOptions {
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

                $checkResponse = Http::withHeaders([
                    'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
                    'Content-Type' => 'application/json',
                ])->post(env('GRAPHQL_URL'), ['query' => $checkQuery]);

                $checkData = $checkResponse->json();

                $product = $checkData['data']['products']['edges'][0]['node'] ?? null;





                // Use shopify_product_id from the database to check if the product already exists
              //  $shopifyProductId = $productGroup->first()->shopify_product_id;

                // if ($shopifyProductId != 'null') {
                //     // If the product exists in the database, use the stored Shopify product ID
                //     $productId = $shopifyProductId;
                // } else {



                    // Collect unique colors and sizes dynamically
                    $uniqueColors = [];
                    $uniqueSizes = [];
                    foreach ($productGroup as $productRecord) {
                        $data = json_decode($productRecord->raw_data, true);
                        if (!in_array($data['ColorCode'], $uniqueColors)) {
                            $uniqueColors[] = $data['ColorCode'];
                        }
                        if (!in_array($data['ItemDim1Code'], $uniqueSizes)) {
                            $uniqueSizes[] = $data['ItemDim1Code'];
                        }
                    }

                    // Format dynamic option values
                    $colorValues = implode(',', array_map(fn($color) => "{name: \"{$color}\"}", $uniqueColors));
                    $sizeValues = implode(',', array_map(fn($size) => "{name: \"{$size}\"}", $uniqueSizes));

                    // Create new product with dynamic options
                    $createMutation = <<<GQL
                        mutation CreateProduct {
                            productCreate(input: {
                                title: "{$title}",
                                descriptionHtml: "{$descriptionHtml}",
                                productOptions: [
                                    {name: "Color",  values: [{name: "red"}]},
                                    {name: "Size",  values: [{name: "2"}]}
                                ]
                            }) {
                                product {
                                    id
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
                    ])->post(env('GRAPHQL_URL'), ['query' => $createMutation]);

                    $createData = $createResponse->json();

                    if (!empty($createData['data']['productCreate']['userErrors'])) {
                        throw new Exception(json_encode($createData['data']['productCreate']['userErrors']));
                    }
                    $productId = $createData['data']['productCreate']['product']['id'];
               // }

                // Get existing variants with price
                $existingVariants = collect($product['variants']['edges'] ?? [])->mapWithKeys(function ($edge) {
                    $variant = $edge['node'];
                    $options = collect($variant['optionValues']);
                    $color = $options->firstWhere('name', 'Color')['value'] ?? '';
                    $size = $options->firstWhere('name', 'Size')['value'] ?? '';
                    return ["{$color}|{$size}" => [
                        'id' => $variant['id'],
                        'barcode' => $variant['barcode'],
                        'price' => $variant['price']
                    ]];
                })->toArray();

                // Prepare new and update variants
                $newVariants = [];
                $updateVariants = [];
                $processedSKUs = [];

                foreach ($productGroup as $productRecord) {
                    $data = json_decode($productRecord->raw_data, true);

                    if (in_array($data['VariantSKU'], $processedSKUs)) {
                        continue;
                    }

                    $combination = "{$data['ColorCode']}|{$data['ItemDim1Code']}";

                    $price = number_format((float)($data['RegularPrice'] ?? 0), 2, '.', '');

                    if (!isset($existingVariants[$combination])) {
                        // $newVariants[] = [
                        //     'barcode' => $data['Barcode'],
                        //     'price' => $price,
                        //     'optionValues' => [
                        //         ['name' => $data['ColorCode'], 'optionName' => 'Color'],
                        //         ['name' => $data['ItemDim1Code'], 'optionName' => 'Size']
                        //     ]
                        // ];


                        $newVariants[] = [
                            'barcode' => $data['Barcode'],
                            'price' => $price,
                            'sku' => $data['VariantSKU'],
                            'optionValues' => [
                                ['name' => $data['ColorCode'], 'optionName' => 'Color'],
                                ['name' => $data['ItemDim1Code'], 'optionName' => 'Size']
                            ]
                        ];
                    } elseif (
                        $existingVariants[$combination]['price'] !== $price ||
                        $existingVariants[$combination]['barcode'] !== $data['Barcode']
                    ) {
                        $updateVariants[] = [
                            'id' => $existingVariants[$combination]['id'],
                            'barcode' => $data['Barcode'],
                            'price' => $price
                        ];
                    }

                    $processedSKUs[] = $data['VariantSKU'];
                }

                // Create new variants
                if (!empty($newVariants)) {

                    $variantMutation = <<<GQL
                        mutation AddVariantsWithSKU {
                            productVariantsBulkCreate(
                                productId: "{$productId}",
                                variants: [
                                    {$this->formatVariants($newVariants)}
                                ]
                            ) {
                                product {
                                id
                                    variants(first: 1) {
                                        edges {
                                            node {
                                                id
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

                    $variantResponse = Http::withHeaders([
                        'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
                        'Content-Type' => 'application/json',
                    ])->post(env('GRAPHQL_URL'), ['query' => $variantMutation]);

                    $variantData = $variantResponse->json();
                      //  dd($variantData);
                    $variantID = $variantData['data']['productVariantsBulkCreate']['product']['variants']['edges'][0]['node']['id'];

                    if (!empty($variantData['data']['productVariantsBulkCreate']['userErrors'])) {
                        throw new Exception(json_encode($variantData['data']['productVariantsBulkCreate']['userErrors']));
                    }
                }

                // Update existing variants with price changes
                if (!empty($updateVariants)) {

                    dd($updateVariants);
                    $updateMutation = <<<GQL
                        mutation UpdateVariants {
                            productVariantsBulkUpdate(
                                productId: "{$productId}",
                                variants: [
                                    {$this->formatUpdateVariants($updateVariants)}
                                ]
                            ) {g`
                                productVariants {
                                    id
                                    barcode
                                    price
                                }
                                userErrors {
                                    field
                                    message
                                }
                            }
                        }
                    GQL;

                    $updateResponse = Http::withHeaders([
                        'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
                        'Content-Type' => 'application/json',
                    ])->post(env('GRAPHQL_URL'), ['query' => $updateMutation]);

                    $updateData = $updateResponse->json();
                    if (!empty($updateData['data']['productVariantsBulkUpdate']['userErrors'])) {
                        throw new Exception(json_encode($updateData['data']['productVariantsBulkUpdate']['userErrors']));
                    }
                }

                // Update database records
                foreach ($productGroup as $productRecord) {
                    DB::table('shopify_products')
                        ->where('id', $productRecord->id)
                        ->update([
                            'status' => 'completed',
                            'shopify_product_id' => $productId,
                            'updated_at' => now()
                        ]);
                }
            } catch (\Exception $e) {
                foreach ($productGroup as $productRecord) {
                    DB::table('shopify_products')
                        ->where('id', $productRecord->id)
                        ->update([
                            'status' => 'failed',
                            'error_message' => $e->getMessage(),
                            'updated_at' => now()
                        ]);
                }
                dd($e->getMessage(), $newVariants, $updateVariants);
            }



            $deleteDefaultMutation = <<<GQL
            mutation DeleteProductVariants {
                productVariantsBulkDelete(
                    productId: "{$productId}",
                    variantsIds: ["{$variantID}"]
                ) {
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

            $deleteDefaultResponse = Http::withHeaders([
                'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
                'Content-Type' => 'application/json',
            ])->post(env('GRAPHQL_URL'), ['query' => $deleteDefaultMutation]);

            $deleteDefaultData = $deleteDefaultResponse->json();

            if (!empty($deleteDefaultData['data']['productVariantsBulkDelete']['userErrors'])) {
                throw new Exception(json_encode($deleteDefaultData['data']['productVariantsBulkDelete']['userErrors']));
            }
        }
    }

    private function formatDescription($data): string
    {
        $points = array_filter([
            $data['ProductAtt01Desc'] ?? '',
            $data['ProductAtt02Desc'] ?? '',
            $data['ProductAtt03Desc'] ?? '',
            $data['ProductAtt04Desc'] ?? '',
            $data['ProductAtt05Desc'] ?? ''
        ]);
        return '<ul>' . implode('', array_map(fn($point) => "<li>{$point}</li>", $points)) . '</ul>';
    }


    private function formatVariants(array $variants): string
    {
        return implode(',', array_map(function ($variant) {
            $optionValues = implode(',', array_map(function ($ov) {
                return "{name: \"{$ov['name']}\", optionName: \"{$ov['optionName']}\"}";
            }, $variant['optionValues']));

            // Add only the sku inside inventoryItem
            $inventoryItem = "{sku: \"{$variant['sku']}\"}";

            return "{barcode: \"{$variant['barcode']}\", price: \"{$variant['price']}\", inventoryItem: {$inventoryItem},optionValues: [{$optionValues}]}";
        }, $variants));
    }



    private function formatUpdateVariants(array $variants): string
    {
        return implode(',', array_map(function ($variant) {
            $inventoryItem = "{sku: \"{$variant['sku']}\"}";
            return "{id: \"{$variant['id']}\", barcode: \"{$variant['barcode']}\", price: \"{$variant['price']}\", inventoryItem: {$inventoryItem}, inventoryManagement: null, inventoryPolicy: NOT_MANAGED}";
        }, $variants));
    }
}
