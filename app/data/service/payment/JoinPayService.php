<?php

namespace app\data\service\payment;

use app\data\service\PaymentService;
use think\admin\extend\HttpExtend;

/**
 * 汇聚支付基础服务
 * Class JoinPayService
 * @package app\store\service\payment
 */
class JoinPayService extends PaymentService
{
    /**
     * 请求地址
     * @var string
     */
    protected $uri;

    /**
     * 应用编号
     * @var string
     */
    protected $appid;

    /**
     * 报备商户号
     * @var string
     */
    protected $trade;

    /**
     * 平台商户号
     * @var string
     */
    protected $mchid;

    /**
     * 平台商户密钥
     * @var string
     */
    protected $mchkey;

    /**
     * 汇聚支付服务初始化
     * @return JoinPayService
     */
    protected function initialize(): JoinPayService
    {
        $this->appid = static::$config['joinpay_appid'];
        $this->trade = static::$config['joinpay_trade'];;
        $this->mchid = static::$config['joinpay_mch_id'];
        $this->mchkey = static::$config['joinpay_mch_key'];
        return $this;
    }

    /**
     * 创建订单支付参数
     * @param string $openid 会员OPENID
     * @param string $orderNo 交易订单单号
     * @param string $payAmount 交易订单金额（元）
     * @param string $payTitle 交易订单名称
     * @param string $payDescription 订单订单描述
     * @return array
     * @throws \think\Exception
     */
    public function create(string $openid, string $orderNo, string $payAmount, string $payTitle, string $payDescription): array
    {
        $types = [
            static::PAYMENT_JOINPAY_GZH => 'WEIXIN_GZH',
            static::PAYMENT_JOINPAY_XCX => 'WEIXIN_XCX',
        ];
        if (isset($types[static::$type])) {
            $type = $types[static::$type];
        } else {
            throw new \think\Exception('支付类型[' . static::$type . ']未配置定义！');
        }
        try {
            $data = [
                'p0_Version'         => '1.0',
                'p1_MerchantNo'      => $this->mchid,
                'p2_OrderNo'         => $orderNo,
                'p3_Amount'          => $payAmount * 100,
                'p4_Cur'             => '1',
                'p5_ProductName'     => $payTitle,
                'p6_ProductDesc'     => $payDescription,
                'p9_NotifyUrl'       => sysuri('@data/api.notify/joinpay/scene/order', [], false, true),
                'q1_FrpCode'         => $type ?? '',
                'q5_OpenId'          => $openid,
                'q7_AppId'           => $this->appid,
                'qa_TradeMerchantNo' => $this->trade,
            ];
            if (empty($data['q5_OpenId'])) unset($data['q5_OpenId']);
            $this->uri = 'https://www.joinpay.com/trade/uniPayApi.action';
            $result = $this->_doReuest($data);
            if (is_array($result) && isset($result['ra_Code']) && intval($result['ra_Code']) === 100) {
                return json_decode($result['rc_Result'], true);
            } elseif (is_array($result) && isset($result['rb_CodeMsg'])) {
                throw new \think\Exception($result['rb_CodeMsg']);
            } else {
                throw new \think\Exception('获取预支付码失败！');
            }
        } catch (\Exception $exception) {
            throw new \think\Exception($exception->getMessage(), $exception->getCode());
        }
    }

    /**
     * 查询订单数据
     * @param string $orderNo
     * @return array
     */
    public function query(string $orderNo): array
    {
        $this->uri = 'https://www.joinpay.com/trade/queryOrder.action';
        return $this->_doReuest(['p1_MerchantNo' => $this->mchid, 'p2_OrderNo' => $orderNo]);
    }

    /**
     * 支付结果处理
     * @return string
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function notify(): string
    {
        $notify = $this->app->request->get();
        foreach ($notify as &$item) $item = urldecode($item);
        if (empty($notify['hmac']) || $notify['hmac'] !== $this->_doSign($notify)) {
            return 'error';
        }
        if (isset($notify['r6_Status']) && intval($notify['r6_Status']) === 100) {
            if ($this->updateOrder($notify['r2_OrderNo'], $notify['r9_BankTrxNo'], $notify['r3_Amount'], 'joinpay')) {
                return 'success';
            }
        }
        return 'error';
    }

    /**
     * 请求数据签名
     * @param array $data
     * @return string
     */
    private function _doSign(array $data): string
    {
        ksort($data);
        unset($data['hmac']);
        return md5(join('', $data) . $this->mchkey);
    }

    /**
     * 执行数据请求
     * @param array $data
     * @return array
     */
    private function _doReuest($data = []): array
    {
        $data['hmac'] = $this->_doSign($data);
        return json_decode(HttpExtend::post($this->uri, $data), true);
    }
}