<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

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
            // dd($runProcResponse);
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
        $products = DB::table('shopify_products')
            ->where('status', 'pending')
            ->limit(30)
            ->get();

        foreach ($products as $productRecord) {
            $data = json_decode($productRecord->raw_data, true);

            try {
                // Build GraphQL mutation

                $mutation = <<<GQL
                                mutation CreateProduct {
                                    productCreate(input: {
                                        title: "{$data['ItemDescription']}",
                                        descriptionHtml: "{$data['ProductAtt01Desc']}",
                                        productOptions: [
                                        {
                                            name: "Color",
                                            values: [
                                            { name: "{$data['ColorCode']}" }
                                            ]
                                        }
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


                        


                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
                    'Content-Type' => 'application/json',
                ])->post(env('GRAPHQL_URL'), [
                    'query' => $mutation
                ]);

               
                $productId = $response->json('data.productCreate.product.id');

                $variantMutation = <<<GQL
                mutation AddVariants {
                    productVariantsBulkCreate(
                        productId: "{$productId}",
                        variants: [
                        {
                           
                            barcode: "{$data['Barcode']}",
                            price: 0.00,
                            optionValues: [
                            { name: "{$data['ColorDescription']}", optionName: "Color" }
                            ]
                        }
                        ]
                    ) {
                        productVariants {
                        id
                        title
                        sku
                        barcode
                        }
                        userErrors {
                        field
                        message
                        }
                    }
                }
            GQL;
               
                // $variantResponse = Http::withHeaders([
                //     'X-Shopify-Access-Token' => env('SHOPIFY_TOKEN'),
                //     'Content-Type' => 'application/json',
                // ])->post(env('GRAPHQL_URL'), [
                //     'query' => $variantMutation
                // ]);

             












                DB::table('shopify_products')->where('id', $productRecord->id)->update([
                    'status' => 'completed',
                    'updated_at' => now()
                ]);
            } catch (\Exception $e) {
                DB::table('shopify_products')->where('id', $productRecord->id)->update([
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                    'updated_at' => now()
                ]);
            }
        }
    }
}


next i want to create product to shopify and i am using 2025-01 version of graphql