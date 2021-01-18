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

/**
 * Script file of Joomla CMS
 *
 * @since  1.6.4
 */
include_once 'TencentcloudCosAction.php';

class PlgContentTencentcloud_cosInstallerScript
{
    /**
     * db
     * @var JDatabaseDriver|null
     */
    private $db;

    private $cos_object;

    public function __construct()
    {
        $this->db = JFactory::getDbo();
        $this->cos_object = new TencentcloudCosAction();
    }

    /**
     * 安装事件
     * @param string $action
     * @param object $installer
     * @return bool
     */
    public function postflight($action, $installer)
    {
        try {
            if (!$this->cos_object->isTableExist('#__tencentcloud_conf')) {
                $this->cos_object->createConfTable();
            }

            if (!$this->cos_object->isTableExist('#__tencentcloud_plugin_conf')) {
                $this->cos_object->createPluginConfTable();
            }

            //获取配置
            $conf = $this->cos_object->getSiteInfo();
            //如果没有腾讯配置，则认为是第一次安装,写入初始化配置

            if (!$conf) {
                $this->cos_object->setSiteInfo();
            }
            $this->cos_object->report('activate');
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }


    /**
     * 卸载事件
     * @param object $installer
     * @return bool
     */
    public function uninstall($installer)
    {
        try {
            $this->cos_object->report('uninstall');
            $this->cos_object->dropPluginConfTable();
            return true;
        } catch (RuntimeException $e) {
            return false;
        }

    }
}