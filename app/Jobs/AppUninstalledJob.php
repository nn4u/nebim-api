<?php 
namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Osiset\ShopifyApp\Contracts\Objects\Values\ShopDomain;
use stdClass;
use Log;
use Illuminate\Support\Facades\DB;

use App\Models\User;
class AppUninstalledJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Shop's myshopify domain
     *
     * @var ShopDomain|string
     */
    public $shopDomain;

    /**
     * The webhook data
     *
     * @var object
     */
    public $data;

    /**
     * Create a new job instance.
     *
     * @param string   $shopDomain The shop's myshopify domain.
     * @param stdClass $data       The webhook data (JSON decoded).
     *
     * @return void
     */
    public function __construct($shopDomain, $data)
    {
        Log::info('Here app un-installed');
        $this->shopDomain = $shopDomain;
        $this->data = $data;


       DB::table('users')->where('name', $shopDomain)->delete();


    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(
    IShopCommand $shopCommand, IShopQuery $shopQuery, CancelCurrentPlan $cancelCurrentPlanAction
    ): bool {
        // Convert the domain
        $this->domain = ShopDomain::fromNative($this->domain);
        // Get the shop
       
        $shop = $shopQuery->getByDomain($this->domain);
        $shopId = $shop->getId();
        // Cancel the current plan
        $cancelCurrentPlanAction($shopId);
        // Purge shop of token, plan, etc.
        $shopCommand->clean($shopId);
        // Soft delete the shop.
        $shopCommand->softDelete($shopId);
        return true;
    }
}
