<?php

/*
 * Copyright (C) 2020 Tencent Cloud.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

defined('_JEXEC') or die;

require_once 'vendor/autoload.php';

class TencentcloudCosAction
{
    /**
     * db
     * @var JDatabaseDriver|null
     */
    private $db;

    /**
     * 插件商
     * @var string
     */
    private $name = 'tencentcloud';

    /**
     * 上报url
     * @var string
     */
    private $log_server_url = 'https://appdata.qq.com/upload';

    /**
     * 应用名称
     * @var string
     */
    private $site_app = 'Joomla';

    /**
     * 插件类型
     * @var string
     */
    private $plugin_type = 'cos';
    private $secret_id;
    private $secret_key;
    private $region;
    private $bucket;


    /**
     * tencent_cos constructor.
     * @param $cos_options
     */
    public function __construct()
    {
        $this->db = JFactory::getDbo();
        $this->init();
    }

    /**
     * 初始化配置
     */
    private function init()
    {
        $cos_options = $this->getCosOptions();
        $cos_options = !empty($cos_options) ? $cos_options : array();
        $this->secret_id = isset($cos_options['secret_id']) ? $cos_options['secret_id'] : '';
        $this->secret_key = isset($cos_options['secret_key']) ? $cos_options['secret_key'] : '';
        $this->region = isset($cos_options['region']) ? $cos_options['region'] : '';
        $this->bucket = isset($cos_options['bucket']) ? $cos_options['bucket'] : '';
        return true;
    }

    /**
     * 获取腾讯云对象存储插件的用户密钥
     * @return array|bool   用户密钥
     */
    public function getCosOptions()
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(array('params', 'type')))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('name') . " = 'plg_content_tencentcloud_cos'");
        $db->setQuery($query);

        $params = $db->loadAssoc();
        if (empty($params) || !isset($params, $params['params'])) {
            return false;
        }
        return json_decode($params['params'], true);
    }

    /**
     * 返回cos对象
     * @param array $options 用户自定义插件参数
     * @return \Qcloud\Cos\Client
     */
    private function getCosClient($secret_id, $secret_key, $region)
    {
        if (empty($region) || empty($secret_id) || empty($secret_key)) {
            return false;
        }

        return new Qcloud\Cos\Client(
            array(
                'region' => $region,
                'schema' => ($this->isHttps() === true) ? "https" : "http",
                'credentials' => array(
                    'secretId' => $secret_id,
                    'secretKey' => $secret_key
                )
            )
        );
    }

    /**
     * 判断是否为https请求
     * @return bool
     */
    private function isHttps()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
            return true;
        } elseif (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
            return true;
        } elseif ($_SERVER['SERVER_PORT'] == 443) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * 上传附件
     * @param $source
     * @param $target
     * @return bool
     */
    public function uploadFileToCos($source, $target)
    {
        try {
            if (empty($this->secret_id) || empty($this->secret_key) || empty($this->region) || empty($this->bucket)) {
                return false;
            }
            $cosClient = $this->getCosClient($this->secret_id, $this->secret_key, $this->region);
            $fh = fopen($source, 'rb');
            if ($fh && $cosClient) {
                $result = $cosClient->Upload(
                    $bucket = $this->bucket,
                    $key = $target,
                    $body = $fh
                );

                fclose($fh);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 删除远程附件
     * @param $key
     * @return bool
     */
    public function deleteRemoteAttachment($key)
    {
        $deleteObjects = array();
        if (is_array($key)) {
            $deleteObjects = $key;
        } elseif (is_string($key)) {
            $deleteObjects[] = array(
                'Key' => $key
            );
        } else {
            $deleteObjects = array();
        }
        if (!empty($deleteObjects) && !empty($this->secret_id) && !empty($this->secret_key) &&
            !empty($this->region) && !empty($this->bucket)) {
            $cosClient = $this->getCosClient($this->secret_id, $this->secret_key, $this->region);
            try {
                $result = $cosClient->deleteObjects(array(
                    'Bucket' => $this->bucket,
                    'Objects' => $deleteObjects,
                ));
                return true;
            } catch (Exception $ex) {
                return false;
            }
        }
        return;
    }

    /**
     * 判断文件是否存储
     * @param $key
     * @return bool
     */
    public function isCosRemoteFileExists($key)
    {
        if (empty($this->secret_id) || empty($this->secret_key) || empty($this->region) || empty($this->bucket)) {
            return false;
        }

        try {
            $cosClient = $this->getCosClient($this->secret_id, $this->secret_key, $this->region);
            $result = $cosClient->headObject(array(
                'Bucket' => $this->bucket,
                'Key' => $key,
            ));
            if (is_object($result)) {
                return true;
            } else {
                return false;
            }
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * 检查存储桶是否存在
     * @param $options
     * @return bool
     */
    public function checkCosBucket($options)
    {
        $cosClient = $this->getCosClient($options['secret_id'], $options['secret_key'], $options['region']);
        if (!$cosClient) {
            return false;
        }
        try {
            if ($cosClient->doesBucketExist($options['bucket'])) {
                return true;
            }
            return false;
        } catch (ServiceResponseException $e) {
            return false;
        }
    }

    /**
     * 获取腾讯云配置
     */
    public function getSiteInfo()
    {
        $db = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['site_id', 'site_url', 'uin']))
            ->from($db->quoteName('#__tencentcloud_conf'))
            ->where('1=1 limit 1');
        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (RuntimeException $e) {
            return false;
        }

        return $row;
    }

    /**
     * 写入腾讯云配置
     */
    public function setSiteInfo()
    {
        $name = $this->name;
        $siteId = uniqid('joomla_');
        $siteUrl = $_SERVER['HTTP_HOST'];
        if (isset($_SERVER["REQUEST_SCHEME"])) {
            $siteUrl = $_SERVER["REQUEST_SCHEME"] . '://' . $siteUrl;
        }

        $db = $this->db;
        $query = $db->getQuery(true);
        $query->insert($db->quoteName('#__tencentcloud_conf'))
            ->columns(array($db->quoteName('name'), $db->quoteName('site_id'), $db->quoteName('site_url')))
            ->values($db->quote($name) . ', ' . $db->quote($siteId) . ', ' . $db->quote($siteUrl));
        $db->setQuery($query);

        try {
            $db->execute();
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * 判断表是否存在
     * @param string $table_name
     * @return bool
     */
    public function isTableExist($table_name)
    {
        $db = $this->db;
        $table = $db->replacePrefix($db->quoteName($table_name));
        $table = trim($table, "`");
        $tables = $db->getTableList();
        if (in_array($table, $tables)) {
            return true;
        }
        return false;
    }

    /**
     * 创建腾讯云全局配置表
     * @return bool|void
     */
    public function createConfTable()
    {
        $db = $this->db;
        $serverType = $db->getServerType();
        if ($serverType != 'mysql') {
            return;
        }
        $creaTabSql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__tencentcloud_conf')
            . ' (' . $db->quoteName('name') . " varchar(100) NOT NULL DEFAULT '', "
            . $db->quoteName('site_id') . " varchar(100) NOT NULL DEFAULT '', "
            . $db->quoteName('site_url') . " varchar(255) NOT NULL DEFAULT '', "
            . $db->quoteName('uin') . " varchar(100) NOT NULL DEFAULT '' "
            . ') ENGINE=InnoDB';

        if ($db->hasUTF8mb4Support()) {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;';
        } else {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_unicode_ci;';
        }
        $db->setQuery($creaTabSql)->execute();
        return true;
    }


    /**
     * 创建腾讯云插件配置表
     * @return bool|void
     */
    public function createPluginConfTable()
    {
        $db = $this->db;
        $serverType = $db->getServerType();
        if ($serverType != 'mysql') {
            return;
        }
        $creaTabSql = 'CREATE TABLE IF NOT EXISTS ' . $db->quoteName('#__tencentcloud_plugin_conf')
            . ' (' . $db->quoteName('type') . " varchar(20) NOT NULL DEFAULT '', "
            . $db->quoteName('uin') . " varchar(20) NOT NULL DEFAULT '',"
            . $db->quoteName('use_time') . " int(11) NOT NULL DEFAULT 0"
            . ') ENGINE=InnoDB';

        if ($db->hasUTF8mb4Support()) {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;';
        } else {
            $creaTabSql = $creaTabSql
                . ' DEFAULT CHARSET=utf8 DEFAULT COLLATE=utf8_unicode_ci;';
        }
        $db->setQuery($creaTabSql)->execute();
        return true;
    }


    public function dropPluginConfTable()
    {
        $db = $this->db;
        $serverType = $db->getServerType();
        if ($serverType != 'mysql') {
            return;
        }
        $creaTabSql = 'DROP TABLE IF EXISTS ' . $db->quoteName('#__tencentcloud_plugin_conf');

        $db->setQuery($creaTabSql)->execute();
        return true;
    }

    /**
     * 获取腾讯云插件配置
     * @return bool|mixed|null
     */
    private function getPluginConf()
    {
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->select($db->quoteName(['type', 'uin', 'use_time']))
            ->from($db->quoteName('#__tencentcloud_plugin_conf'))
            ->where($db->quoteName('type') . " = '" . $this->plugin_type . "' limit 1");
        $db->setQuery($query);

        try {
            $row = $db->loadAssoc();
        } catch (RuntimeException $e) {
            return false;
        }

        return $row;
    }


    /**
     * 发送post请求
     * @param string  地址
     * @param mixed   参数
     */
    private static function sendPostRequest($url, $data)
    {
        if (function_exists('curl_init')) {
            ob_start();
            $json_data = json_encode($data);
            $curl = curl_init($url);
            curl_setopt($curl, CURLOPT_HEADER, false);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $json_data);
            curl_setopt($curl, CURLOPT_TIMEOUT, 60);   //设置一秒超时
            curl_exec($curl);
            curl_exec($curl);
            curl_close($curl);
            ob_end_clean();
        }
    }


    /**
     * 发送用户信息（非敏感）
     * @param $data
     * @return bool|void
     */
    private function sendUserExperienceInfo($data)
    {
        if (empty($data) || !is_array($data) || !isset($data['action'])) {
            return;
        }
        $url = $this->log_server_url;
        $this->sendPostRequest($url, $data);
        return true;
    }

    /**
     * @param string $action 上报方法
     */
    public function report($action)
    {
        //数据上报
        $conf = $this->getSiteInfo();
        $pluginConf = $this->getPluginConf();
        $params = $this->getCosOptions();
        $region = isset($params, $params['region']) ? $params['region'] : '';
        $bucket = isset($params, $params['bucket']) ? $params['bucket'] : '';
        if (isset($pluginConf, $pluginConf['uin'])) {
            $uin = $pluginConf['uin'];
        }
        $data = array(
            'action' => $action,
            'plugin_type' => $this->plugin_type,
            'data' => array(
                'site_id' => $conf['site_id'],
                'site_url' => $conf['site_url'],
                'site_app' => $this->site_app,
                'uin' => isset($uin) ? $uin : '',
                'others' => json_encode(array('cos_region' => $region, 'cos_bucket' => $bucket))
            )
        );
        $this->sendUserExperienceInfo($data);
    }
}
