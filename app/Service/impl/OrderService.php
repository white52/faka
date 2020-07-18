<?php
declare (strict_types=1);

namespace App\Service\impl;


use App\Model\Card;
use App\Model\Commodity;
use App\Model\Order;
use App\Model\Pay;
use App\Model\Voucher;
use App\Service\OrderServiceInterface;
use App\Utils\AddressUtil;
use App\Utils\DateUtil;
use App\Utils\HttpUtil;
use App\Utils\StringUtil;
use Core\Exception\JSONException;
use Core\Utils\Bridge;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Class OrderService
 * @package App\Service\impl
 */
class OrderService implements OrderServiceInterface
{

    /**
     * @inheritDoc
     * @throws JSONException
     */
    public function trade(string $contact, int $num, string $pass, int $payId, int $device, string $voucher, int $commodityId, string $ip): array
    {
        if ($commodityId == 0) {
            throw new JSONException('请选择商品再进行下单');
        }

        if ($num <= 0) {
            throw new JSONException("最低购买数量为1~");
        }

        //查询商品
        $commodity = Commodity::query()->find($commodityId);

        if (!$commodity) {
            throw new JSONException("商品不存在");
        }

        if ($commodity->status != 1) {
            throw new JSONException("当前商品已停售，请稍后再试");
        }

        if (mb_strlen($contact) < 4) {
            throw new JSONException("联系方式不能低于4个字符");
        }

        $regx = ['', '/^1[3456789]\d{9}$/', '/.*(.{2}@.*)$/i', '/[1-9]{1}[0-9]{4,11}/'];
        $msg = ['', '手机', '邮箱', 'QQ号'];
        if ($commodity->contact != 0) {
            if (!preg_match($regx[$commodity->contact], $contact)) {
                throw new JSONException("您输入的{$msg[$commodity->contact]}格式不正确！");
            }
        }

        //检测库存
        $count = Card::query()->where("commodity_id", $commodityId)->where("status", 0)->count();
        if ($count == 0 || $num > $count) {
            throw new JSONException("当前商品库存不足，请稍后再试~");
        }
        //开始计算订单金额
        $amount = $this->getAmount($num, $commodity);

        //获取支付方式
        $pay = Pay::query()->find($payId);

        if (!$pay) {
            throw new JSONException("该支付方式不存在");
        }

        if ($pay->status != 1) {
            throw new JSONException("当前支付方式已停用，请换个支付方式再进行支付");
        }


        //创建订单
        return DB::transaction(function () use ($amount, $payId, $commodityId, $ip, $device, $pass, $contact, $voucher, $num, $pay) {
            $date = DateUtil::current();
            $order = new Order();
            $order->trade_no = StringUtil::generateTradeNo();
            $order->amount = $amount;
            $order->pay_id = $payId;
            $order->commodity_id = $commodityId;
            $order->create_date = $date;
            $order->create_ip = $ip;
            $order->create_device = $device;
            $order->contact = $contact;
            $order->status = 0;
            $order->num = $num;

            if ($pass != '') {
                $order->pass = $pass;
            }

            //获取优惠卷
            if (mb_strlen($voucher) == 8) {
                $voucherModel = Voucher::query()->where("commodity_id", $commodityId)->where("voucher", $voucher)->first();
                if (!$voucherModel) {
                    throw new JSONException("该优惠卷不存在或不属于该商品");
                }
                if ($voucherModel->status != 0) {
                    throw new JSONException("该优惠卷已被使用过了");
                }

                if ($voucherModel->money > $amount) {
                    throw new JSONException("该优惠卷抵扣的金额大于本次消费，无法使用该优惠卷进行抵扣");
                }

                //进行优惠
                $order->amount = $amount - $voucherModel->money;

                $voucherModel->status = 1;
                $voucherModel->contact = $contact;
                $voucherModel->use_date = $date;
                $voucherModel->save();

                $order->voucher_id = $voucherModel->id;
            }

            $url = '';

            //判断金额，检测是否为免费订单
            if ($order->amount == 0) {
                if ($num > 1) {
                    throw new JSONException("当前商品是免费商品，一次性最多领取1个");
                }

                $order->status = 1;
                $order->pay_date = $date;
                //取出对应的密钥
                $card = Card::query()->where("commodity_id", $commodityId)->where("status", 0)->first();
                if (!$card) {
                    throw new JSONException("您的手慢了，商品被抢空");
                }
                $card->status = 1;
                $card->contact = $contact;
                $card->buy_date = $date;
                $card->save();

                $order->commodity = $card->card;
            } else {
                //需要进行下单到第三方平台购买
                $payConfig = Bridge::getConfig('pay');

                $postData = [
                    'merchant_id' => $payConfig['merchant_id'],
                    'amount' => $order->amount,
                    'channel_id' => $pay->code,
                    'app_id' => $payConfig['app_id'],
                    'notification_url' => AddressUtil::getUrl() . '/index/api/order/callback',
                    'sync_url' => AddressUtil::getUrl() . '/index/query?tradeNo=' . $order->trade_no,
                    'ip' => $ip,
                    'out_trade_no' => $order->trade_no
                ];

                $postData['sign'] = StringUtil::generateSignature($postData, $payConfig['key']);

                $request = HttpUtil::request('https://lizhifu.net/order/trade', $postData);

                $json = json_decode((string)$request, true);

                if ($json['code'] != 200) {
                    throw new JSONException("当前支付方式不可用，请换个支付方式再试！");
                }

                $url = $json['data']['url'];
            }
            $order->save();

            return ['url' => $url, 'amount' => $order->amount, 'tradeNo' => $order->trade_no];
        });
    }

    /**
     * 获取购买价格
     * @param int $num
     * @param Commodity $commodity
     * @return float
     */
    private function getAmount(int $num, Commodity $commodity): float
    {
        $price = $commodity->price;
        if ($commodity->wholesale_status == 1) {
            $list = [];
            $wholesales = explode(PHP_EOL, trim(trim((string)$commodity->wholesale), PHP_EOL));
            foreach ($wholesales as $item) {
                $s = explode('-', $item);
                if (count($s) == 2) {
                    $list[$s[0]] = $s[1];
                }
            }
            krsort($list);
            foreach ($list as $k => $v) {
                if ($num >= $k) {
                    $price = $v;
                    break;
                }
            }
        }
        return $num * $price;
    }

    /**
     * @inheritDoc
     */
    public function callback(array $map): string
    {
        //验证签名
        $payConfig = Bridge::getConfig('pay');
        $user = Bridge::getConfig('user');
        if ($map['sign'] != StringUtil::generateSignature($map, $payConfig['key'])) {
            return 'sign error';
        }
        //验证状态
        if ($map['status'] != 1) {
            return 'status error';
        }

        return DB::transaction(function () use ($map, $user) {
            //获取订单
            $order = Order::query()->where("trade_no", $map['out_trade_no'])->where("status", 0)->first();
            if (!$order) {
                throw new JSONException("order not found");
            }
            //取出和订单相同数量的卡密
            $cards = Card::query()->where("status", 0)->limit($order->num)->get();
            $order->pay_date = DateUtil::current();
            $order->status = 1;
            if (count($cards) != $order->num) {
                $order->commodity = '很抱歉，当前库存不足，自动发卡失败，请联系客服QQ：' . $user['qq'];
            } else {
                //将全部卡密置已销售状态
                $ids = [];
                $cardc = '';
                foreach ($cards as $card) {
                    $ids[] = $card->id;
                    $cardc .= $card->card . PHP_EOL;
                }
                try {
                    $rows = Card::query()->whereIn("id", $ids)->update(['buy_date' => $order->pay_date, 'contact' => $order->contact, 'status' => 1]);
                    if ($rows == 0) {
                        $order->commodity = '很抱歉，当前库存不足，自动发卡失败，请联系客服QQ：' . $user['qq'];
                    } else {
                        //将卡密写入到订单中
                        $order->commodity = trim($cardc, PHP_EOL);
                    }
                } catch (\Exception $e) {
                    $order->commodity = '很抱歉，当前库存不足，自动发卡失败，请联系客服QQ：' . $user['qq'];
                }
            }

            $order->save();
            return 'success';
        });
    }
}