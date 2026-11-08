<?php
/**
 * 原生 PHP 模板渲染。布局用 $content 接收子模板输出。
 * 不用模板引擎：加密后没有编译缓存目录，原生模板最稳。
 */
declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = self::partial($template, $data);
        if ($layout === null || $layout === '') {
            return $content;
        }
        return self::partial($layout, $data + ['content' => $content]);
    }

    public static function partial(string $template, array $data = []): string
    {
        $file = BASE_PATH . '/app/Views/' . str_replace('..', '', $template) . '.php';
        if (!is_file($file)) {
            throw new \RuntimeException('模板不存在：' . $template);
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $file;
        return (string) ob_get_clean();
    }
}
