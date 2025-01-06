<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 
 * AriaRSS 用于帮助保护博客的 RSS 内容。
 *
 *
 * @package AriaRSS 
 * @version 1.0.0
 * @author 寻鹤
 * @link https://bluehe.cn/
 * @license GPL-3.0-or-later
 */
class AriaRSS_Plugin implements Typecho_Plugin_Interface
{
  
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Archive')->footer = array('AriaRSS_Plugin', 'generateRSS');
    }

    public static function deactivate()
    {
        @unlink(__TYPECHO_ROOT_DIR__ . '/rss.xml');
    }

    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $numOfPosts = new Typecho_Widget_Helper_Form_Element_Text('numOfPosts', NULL, '10', _t('文章数量'), _t('设定生成 RSS 文件时包含的文章数量'));
        $form->addInput($numOfPosts->addRule('isInteger', _t('文章数量必须是整数')));

        // 隐私保护内容配置
        $privacyProtection = new Typecho_Widget_Helper_Form_Element_Textarea(
            'privacyProtection', 
            NULL, 
            '隐私保护：由于图片显示问题，部分内容已被隐藏，详细信息请通过原文链接查看。',
            _t('隐私保护内容'),
            _t('该内容将显示在 RSS 内容的隐私保护部分。')
        );
        $form->addInput($privacyProtection);

        // 版权声明内容配置
        $copyrightNotice = new Typecho_Widget_Helper_Form_Element_Textarea(
            'copyrightNotice', 
            NULL, 
            '版权声明：本文所有内容均采用 (CC BY-NC-ND 4.0)，转载需保留出处。',
            _t('版权声明内容'),
            _t('该内容将显示在 RSS 内容的版权声明部分。')
        );
        $form->addInput($copyrightNotice);
    }


    public static function personalConfig(Typecho_Widget_Helper_Form $form) {}


    public static function generateRSS() 
    {
        $db = Typecho_Db::get();
        $options = Typecho_Widget::widget('Widget_Options');
        $numOfPosts = $options->plugin('AriaRSS')->numOfPosts;
        $privacyProtection = $options->plugin('AriaRSS')->privacyProtection;
        $copyrightNotice = $options->plugin('AriaRSS')->copyrightNotice;


        $rows = $db->fetchAll($db->select()
            ->from('table.contents')
            ->where('type = ?', 'post')
            ->where('status = ?', 'publish')
            ->order('created', Typecho_Db::SORT_DESC)
            ->limit($numOfPosts));

        require_once 'Parsedown.php';
        $Parsedown = new Parsedown();


        $rssFeed = '<?xml version="1.0" encoding="UTF-8" ?>' . PHP_EOL;
        $rssFeed .= '<rss version="2.0"' . PHP_EOL;
        $rssFeed .= 'xmlns:content="http://purl.org/rss/1.0/modules/content/"' . PHP_EOL;
        $rssFeed .= 'xmlns:dc="http://purl.org/dc/elements/1.1/"' . PHP_EOL;
        $rssFeed .= 'xmlns:atom="http://www.w3.org/2005/Atom"' . PHP_EOL;
        $rssFeed .= 'xmlns:slash="http://purl.org/rss/1.0/modules/slash/">' . PHP_EOL;
        $rssFeed .= '<channel>' . PHP_EOL;
        $rssFeed .= '<title>' . htmlspecialchars($options->title) . '</title>' . PHP_EOL;
        $rssFeed .= '<link>' . htmlspecialchars($options->siteUrl) . '</link>' . PHP_EOL;
        $rssFeed .= '<description>' . htmlspecialchars($options->description) . '</description>' . PHP_EOL;
        $rssFeed .= '<atom:link href="' . htmlspecialchars($options->siteUrl . 'rss.xml') . '" rel="self" type="application/rss+xml" />' . PHP_EOL;
        $rssFeed .= '<lastBuildDate>' . date(DATE_RSS) . '</lastBuildDate>' . PHP_EOL;


        foreach ($rows as $row) {
            $permalink = Typecho_Router::url('post', $row, $options->siteUrl);

            $author = $db->fetchRow($db->select('screenName')
                ->from('table.users')
                ->where('uid = ?', $row['authorId']));

            $rssFeed .= '<item>' . PHP_EOL;
            $rssFeed .= '<title>' . htmlspecialchars($row['title']) . '</title>' . PHP_EOL;
            $rssFeed .= '<link>' . htmlspecialchars($permalink) . '</link>' . PHP_EOL;
            $rssFeed .= '<guid isPermaLink="false">' . htmlspecialchars($permalink) . '</guid>' . PHP_EOL;
            $rssFeed .= '<pubDate>' . date(DATE_RSS, $row['created']) . '</pubDate>' . PHP_EOL;
            $rssFeed .= '<dc:creator>' . htmlspecialchars($author['screenName']) . '</dc:creator>' . PHP_EOL;


            $categories = $db->fetchAll($db->select()
                ->from('table.metas')
                ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
                ->where('table.relationships.cid = ?', $row['cid'])
                ->where('table.metas.type = ?', 'category'));
            if (!empty($categories)) {
                foreach ($categories as $category) {
                    $rssFeed .= '<category><![CDATA[' . $category['name'] . ']]></category>' . PHP_EOL;
                }
            }

            $tags = $db->fetchAll($db->select()
                ->from('table.metas')
                ->join('table.relationships', 'table.metas.mid = table.relationships.mid')
                ->where('table.relationships.cid = ?', $row['cid'])
                ->where('table.metas.type = ?', 'tag'));
            if (!empty($tags)) {
                foreach ($tags as $tag) {
                    $rssFeed .= '<category><![CDATA[' . $tag['name'] . ']]></category>' . PHP_EOL;
                }
            }


            $customDesc = $db->fetchRow($db->select('str_value')
                ->from('table.fields')
                ->where('cid = ?', $row['cid'])
                ->where('name = ?', 'postjj'));
            if (!empty($customDesc['str_value'])) {
                $rssFeed .= '<description><![CDATA[' . htmlspecialchars($customDesc['str_value']) . ']]></description>' . PHP_EOL;
            } else {
                if (!empty($row['text'])) {
                    $htmlContent = $Parsedown->text($row['text']);
                    $excerpt = mb_substr(strip_tags($htmlContent), 0, 128, 'UTF-8') . '...';
                    $rssFeed .= '<description><![CDATA[' . $excerpt . ']]></description>' . PHP_EOL;
                }
            }


            if (!empty($row['text'])) {
                $row['text'] = preg_replace('/<!--markdown-->/i', '', $row['text']);  // 去除 <!--markdown--> 注释
                $htmlContent = $Parsedown->text($row['text']);
                

                $contentExcerpt = mb_substr(strip_tags($htmlContent), 0, 100, 'UTF-8');
                $contentExcerpt .= '...<br /><br />' . htmlspecialchars($privacyProtection) . '<br />';
                $contentExcerpt .= htmlspecialchars($copyrightNotice) . '<br />';
                $contentExcerpt .= '原文链接：<a href="' . htmlspecialchars($permalink) . '">' . htmlspecialchars($row['title']) . '</a>';
                
                $rssFeed .= '<content:encoded><![CDATA[' . PHP_EOL . $contentExcerpt . PHP_EOL . ']]></content:encoded>' . PHP_EOL;
            }


            $commentsNum = $db->fetchObject($db->select(array('COUNT(*)' => 'num'))
                ->from('table.comments')
                ->where('cid = ?', $row['cid'])
                ->where('status = ?', 'approved'))->num;

            $commentsLink = $permalink . '#comments';
            $rssFeed .= '<slash:comments>' . htmlspecialchars($commentsNum) . '</slash:comments>' . PHP_EOL;
            $rssFeed .= '<comments>' . htmlspecialchars($commentsLink) . '</comments>' . PHP_EOL;


            $banner = $db->fetchRow($db->select('str_value')
                ->from('table.fields')
                ->where('cid = ?', $row['cid'])
                ->where('name = ?', 'banner'));
            if (!empty($banner['str_value'])) {
                $rssFeed .= '<enclosure url="' . htmlspecialchars($banner['str_value']) . '" length="0" type="image/jpeg" />' . PHP_EOL;
            }

            $rssFeed .= '</item>' . PHP_EOL;
        }

        $rssFeed .= '</channel>' . PHP_EOL;
        $rssFeed .= '</rss>' . PHP_EOL;


        $rssFile = __TYPECHO_ROOT_DIR__ . '/rss.xml';


        if (!is_writable($rssFile)) {
            chmod($rssFile, 0777);  
        }


        if (file_exists($rssFile)) {
            unlink($rssFile);
        }

        file_put_contents($rssFile, $rssFeed);
    }
}
?>
