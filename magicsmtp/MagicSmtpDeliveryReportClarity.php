<?php
defined('MW_PATH') or exit('No direct script access allowed');

/** Presentation only: preserve MailWizz status, confirmation and retry semantics. */
class MagicSmtpDeliveryReportClarity
{
    public static function gridProperties(array $properties, $controller)
    {
        if (!$controller || $controller->getId() !== 'campaign_reports'
            || !$controller->getAction() || $controller->getAction()->getId() !== 'delivery'
            || empty($properties['dataProvider']) || empty($properties['columns'])) {
            return $properties;
        }
        $serverIds = [];
        foreach ($properties['dataProvider']->getData() as $row) {
            if (!empty($row->server_id)) $serverIds[] = (int)$row->server_id;
        }
        if (!$serverIds) return $properties;
        $criteria = new CDbCriteria();
        $criteria->select = 'server_id,type';
        $criteria->addInCondition('server_id', array_values(array_unique($serverIds)));
        $criteria->addInCondition('type', ['magic-smtp', 'magic-smtp-web-api']);
        $magicIds = [];
        foreach (DeliveryServer::model()->findAll($criteria) as $server) $magicIds[(int)$server->server_id] = true;
        if (!$magicIds) return $properties;
        $explanation = 'Accepted by the sending server; final recipient delivery is tracked separately.';
        foreach ($properties['columns'] as &$column) {
            if (!is_array($column) || !in_array($column['name'] ?? '', ['status', 'delivery_confirmed'], true)) continue;
            $name = $column['name'];
            $column['type'] = 'raw';
            $column['value'] = static function ($data) use ($name, $magicIds, $explanation) {
                $value = $name === 'status' ? $data->getStatusName() : t('app', ucfirst((string)$data->delivery_confirmed));
                $label = html_encode((string)$value);
                if (!isset($magicIds[(int)$data->server_id]) || $data->status !== CampaignDeliveryLog::STATUS_SUCCESS
                    || ($name === 'delivery_confirmed' && $data->delivery_confirmed !== 'yes')) return $label;
                return CHtml::tag('span', ['class'=>'magic-smtp-acceptance-help', 'tabindex'=>0,
                    'title'=>$explanation, 'aria-label'=>(string)$value . '. ' . $explanation], $label . ' &#9432;');
            };
        }
        unset($column);
        return $properties;
    }
}
