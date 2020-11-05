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

require_once 'TencentcloudCosAction.php';

class PlgContentTencentcloud_cos extends JPlugin
{

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Smart Search after save content method.
     * Content is passed by reference, but after the save, so no changes will be saved.
     * Method is called right after the content is saved.
     *
     * @param string $context The context of the content passed to the plugin (added in 1.6)
     * @param object $article A JTableContent object
     * @param bool $isNew If the content has just been created
     *
     * @return  void
     *
     * @since   2.5
     */
    public function onContentAfterSave($context, $article, $isNew)
    {
        if ($context === 'com_media.file') {
            $tencent_cos = new TencentcloudImsAction();
            $cos_file_path = $this->getUploadFilePath($article);
            if (empty($cos_file_path)) {
                return;
            }
            $tencent_cos->uploadFileToCos($article->filepath, $cos_file_path);
        }
        return;
    }

    /**
     * Smart Search after delete content method.
     * Content is passed by reference, but after the deletion.
     * 保存文章：$content:com_content.article
     * @param string $context The context of the content passed to the plugin (added in 1.6).
     * @param object $article A JTableContent object.
     *
     * @return  void
     *
     * @since   2.5
     */
    public function onContentBeforeDelete($context, $article)
    {
        if ($context === 'com_media.file') {
            $cos_file_path = $this->getUploadFilePath($article);
            if (empty($cos_file_path)) {
                return;
            }
            $drop_files[] = array('Key' => $cos_file_path);
            $tencent_cos = new TencentcloudImsAction();
            $tencent_cos->deleteRemoteAttachment($drop_files);
        }
        return;
    }

    /**
     * Plugin that loads module positions within content
     *
     * @param string $context The context of the content being passed to the plugin.
     * @param object   &$article The article object.  Note $article->text is also available
     * @param mixed    &$params The article params
     * @param integer $page The 'page' number
     *
     * @return  mixed   true if there is an error. Void otherwise.
     *
     * @since   1.6
     */
    public function onContentPrepare($context, &$article, &$params, $page = 0)
    {
        if ($context === 'com_content.article' || $context === 'com_content.category') {
            $cos_options = (new TencentcloudImsAction())->getCosOptions();
            if (empty($cos_options) || !isset($cos_options['remote_url'])) {
                return;
            }

            $images = json_decode($article->images, true);
            if (isset($images, $images['image_intro']) && !empty($images['image_intro'])) {
                $images['image_intro'] = $cos_options['remote_url'] . "/" . $images['image_intro'];
            }

            if (isset($images, $images['image_fulltext']) && !empty($images['image_fulltext'])) {
                $images['image_fulltext'] = $cos_options['remote_url'] . "/" . $images['image_fulltext'];
            }
            $article->images = json_encode($images);

            $article->text = $this->replaceImageUrl($article->text, $cos_options);
            $article->introtext = $this->replaceImageUrl($article->introtext, $cos_options);
            return;
        }

    }

    private function replaceImageUrl($text, $cos_options)
    {
        if (empty($text)) {
            return $text;
        }
        preg_match_all('/<a[^>]+href=([\'"])(?<href>.+?)\1[^>]*>/i', $text, $hrefResult);
        preg_match_all('/<img[^>]+src=([\'"])(?<src>.+?)\1[^>]*>/i', $text, $srcResult);
        $result = array_merge($hrefResult['href'], $srcResult['src']);

        foreach ($result as $link) {
            $text = str_replace($link, $cos_options['remote_url'] . "/" . ltrim($link, '/'), $text);
        }
        return $text;
    }

    /**
     * 获取上传文件在媒体库中的相对目录，目录字符串首部无"/"
     *
     * @param object $article 附件对象
     *
     * @return string|string[]
     */
    private function getUploadFilePath($article)
    {
        return str_replace(JPATH_ROOT . '/', '', $article->filepath);
    }
}
