<?php declare(strict_types=1);
define('MW_PATH', __DIR__);
function html_encode($value) { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function t($category, $value) { return $value; }
class CDbCriteria { public $select; public function addInCondition($name, $values) {} }
class DeliveryServer {
    public static function model() { return new self(); }
    public function findAll($criteria) { return [(object)['server_id'=>42]]; }
}
class CampaignDeliveryLog {
    const STATUS_SUCCESS = 'success';
    public $server_id=42, $status='success', $delivery_confirmed='yes';
    public function getStatusName() { return ucfirst($this->status); }
}
class CHtml {
    public static function tag($tag, $attrs, $content) {
        $html='<'.$tag; foreach ($attrs as $k=>$v) $html.=' '.$k.'="'.html_encode((string)$v).'"';
        return $html.'>'.$content.'</'.$tag.'>';
    }
}
require dirname(__DIR__).'/magicsmtp/MagicSmtpDeliveryReportClarity.php';
function check($ok, $label) { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
$controller = new class {
    public function getId() { return 'campaign_reports'; }
    public function getAction() { return new class { public function getId() { return 'delivery'; } }; }
};
$provider = new class { public function getData() { return [new CampaignDeliveryLog()]; } };
$props=['dataProvider'=>$provider,'columns'=>[['name'=>'status'],['name'=>'delivery_confirmed'],['name'=>'message']]];
check(MagicSmtpDeliveryReportClarity::gridProperties($props, null)===$props, 'unrelated controller unchanged');
$result=MagicSmtpDeliveryReportClarity::gridProperties($props,$controller);
$status=$result['columns'][0]['value']; $sent=$result['columns'][1]['value']; $row=new CampaignDeliveryLog();
check(strpos($status($row), 'Accepted by the sending server; final recipient delivery is tracked separately.')!==false, 'success explains acceptance');
check(strpos($sent($row), 'tabindex="0"')!==false && strpos($sent($row), 'aria-label=')!==false, 'Yes tooltip keyboard accessible');
$row->status='error'; check($status($row)==='Error' && $sent($row)==='Yes', 'failed outcome has no acceptance claim');
$row->status='success'; $row->delivery_confirmed='no'; check($sent($row)==='No', 'unconfirmed value preserved');
$row->server_id=99; check($status($row)==='Success', 'other delivery server untouched');
$row->status='<script>alert(1)</script>'; check(strpos($status($row), '<script>')===false, 'raw renderer still escapes values');
check($result['columns'][2]===$props['columns'][2], 'other columns untouched');
check($row->delivery_confirmed==='no' && $row->server_id===99, 'no row mutations');
