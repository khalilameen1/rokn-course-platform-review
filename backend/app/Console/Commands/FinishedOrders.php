<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Traits\NotifyUsers;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FinishedOrders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'finishedOrder:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $orders = Order::where([['finish_at', Carbon::now()->format('Y-m-d H:i:00')], ['status_id', 8]])->get();
        foreach ($orders as $order) {
            $info = [
                'message' => app('request')->header('locale') === 'en' ? 'Order Time End' : 'تم انتهاء وقت الطلب'];
            if ($order->user->access_token && $order->user->device_os == 'ios') {
                NotifyUsers::sendIos([$order->user->access_token], $info);
            } elseif ($order->user->access_token && $order->user->device_os == 'android') {
                NotifyUsers::sendAndroid([$order->user->access_token], $info);
            }
            if ($order->provider->access_token && $order->provider->device_os == 'ios') {
                NotifyUsers::sendIos([$order->provider->access_token], $info);
            } elseif ($order->provider->access_token && $order->provider->device_os == 'android') {
                NotifyUsers::sendAndroid([$order->provider->access_token], $info);
            }
        }
        echo Carbon::now()->format('Y-m-d H:i:00');
    }
}
