<?php
/**
 * 对外 HTTP 调用。只做两件事：发请求、把结果原样带回来。
 * 超时和证书校验都是硬性的，不提供关闭开关。
 */
declare(strict_types=1);

namespace App;

final class Http
{
    /**
     * @return array{status:int,body:string,error:string}
     */
    public static function send(string $url, string $body = '', array $headers = [], int $timeout = 15): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'curl 初始化失败'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER     => array_merge(['Expect:'], $headers),
            CURLOPT_USERAGENT      => 'FulkLink/1.0',
        ]);
        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        $err  = (string) curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $code,
            'body'   => is_string($resp) ? $resp : '',
            'error'  => $err,
        ];
    }

    /** @return array{status:int,body:string,error:string} */
    public static function postForm(string $url, array $data, int $timeout = 15): array
    {
        return self::send(
            $url,
            http_build_query($data, '', '&', PHP_QUERY_RFC1738),
            ['Content-Type: application/x-www-form-urlencoded'],
            $timeout
        );
    }

    /** @return array{status:int,body:string,error:string} */
    public static function postJson(string $url, array $data, int $timeout = 15): array
    {
        return self::send(
            $url,
            (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ['Content-Type: application/json'],
            $timeout
        );
    }
}
