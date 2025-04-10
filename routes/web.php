<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: *");
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use App\Http\Controllers\ProductController; 
/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/
   Route::get('/', function () {
      return view('welcome');
   })->middleware(['auth.shopify'])->name('home');

   Route::any('/get-products', [ProductController::Class, 'getProducts'])->name('get.product');
   Route::any('/sync-products', [ProductController::Class, 'syncProduct'])->name('sync.product');
   