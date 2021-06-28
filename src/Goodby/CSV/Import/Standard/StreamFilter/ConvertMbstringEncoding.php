<?php

namespace Goodby\CSV\Import\Standard\StreamFilter;

use php_user_filter;
use RuntimeException;

class ConvertMbstringEncoding extends php_user_filter
{
    /**
     * @var string
     */
    const FILTER_NAMESPACE = 'convert.mbstring.encoding.';

    /**
     * @var bool
     */
    private static $hasBeenRegistered = false;

    /**
     * @var string
     */
    private $fromCharset;

    /**
     * @var string
     */
    private $toCharset;

    /**
     * Return filter name
     * @return string
     */
    public static function getFilterName()
    {
        return self::FILTER_NAMESPACE . '*';
    }

    /**
     * Register this class as a stream filter
     * @throws \RuntimeException
     */
    public static function register()
    {
        if (self::$hasBeenRegistered === true) {
            return;
        }

        if (stream_filter_register(self::getFilterName(), __CLASS__) === false) {
            throw new RuntimeException('Failed to register stream filter: ' . self::getFilterName());
        }

        self::$hasBeenRegistered = true;
    }

    /**
     * Return filter URL
     * @param string $filename
     * @param string $fromCharset
     * @param string $toCharset
     * @return string
     */
    public static function getFilterURL($filename, $fromCharset, $toCharset = null)
    {
        if ($toCharset === null) {
            return sprintf('php://filter/convert.mbstring.encoding.%s/resource=%s', $fromCharset, $filename);
        }else {
            return sprintf('php://filter/convert.mbstring.encoding.%s:%s/resource=%s', $fromCharset, $toCharset, $filename);
        }
    }

    /**
     * @return bool
     */
    public function onCreate()
    {
        if (strpos($this->filtername, self::FILTER_NAMESPACE) !== 0) {
            return false;
        }

        $parameterString = substr($this->filtername, strlen(self::FILTER_NAMESPACE));

        if (!preg_match('/^(?P<from>[-\w]+)(:(?P<to>[-\w]+))?$/', $parameterString, $matches)) {
            return false;
        }

        $this->fromCharset = isset($matches['from']) ? $matches['from'] : 'auto';
        $this->toCharset = isset($matches['to']) ? $matches['to'] : mb_internal_encoding();

        return true;
    }

    /**
     * @param string $in
     * @param string $out
     * @param string $consumed
     * @param $closing
     * @return int
     */
    public function filter($in, $out, &$consumed, $closing)
    {
        $isBucketAppended = false;
        $previousData = $this->buffer;
        $deferredData = '';

        while ($bucket = stream_bucket_make_writeable($in)) {
            $data = $previousData . $bucket->data; // 前回後回しにしたデータと今回のチャンクデータを繋げる
            $consumed += $bucket->datalen;

            // 受け取ったチャンクデータの最後から1文字ずつ削っていって、SJIS的に区切れがいいところまでデータを減らす
            while ($this->needsToNarrowEncodingDataScope($data)) {
                $deferredData = substr($data, -1) . $deferredData; // 削ったデータは後回しデータに付け加える
                $data = substr($data, 0, -1);
            }

            if ($data) { // ここに来た段階で $data は区切りが良いSJIS文字列になっている
                $bucket->data = $this->encode($data);
                stream_bucket_append($out, $bucket);
                $isBucketAppended = true;
            }
        }

        $this->buffer = $deferredData; // 後回しデータ: チャンクデータの句切れが悪くエンコードできなかった残りを次回の処理に回す
        $this->assertBufferSizeIsSmallEnough(); // メモリ不足回避策: バッファを使いすぎてないことを保証する
        return $isBucketAppended ? PSFS_PASS_ON : PSFS_FEED_ME;
    }

    /**
     * SJISで8192バイト区切りで不都合が出る場合への対策
     * @See: https://qiita.com/suin/items/3edfb9cb15e26bffba11
     */

    /**
     * Buffer size limit (bytes)
     *
     * @var int
     */
    private static $bufferSizeLimit = 1024;

    /**
     * @var string
     */
    private $buffer = '';

    public static function setBufferSizeLimit($bufferSizeLimit)
    {
        self::$bufferSizeLimit = $bufferSizeLimit;
    }

    private function needsToNarrowEncodingDataScope($string)
    {
        return !($string === '' || $this->isValidEncoding($string));
    }

    private function isValidEncoding($string)
    {
        return mb_check_encoding($string, 'SJIS-win');
    }

    private function encode($string)
    {
        return mb_convert_encoding($string, 'UTF-8', 'SJIS-win');
    }

    private function assertBufferSizeIsSmallEnough()
    {
        assert(
            strlen($this->buffer) <= self::$bufferSizeLimit,
            sprintf(
                'Streaming buffer size must less than or equal to %u bytes, but %u bytes allocated',
                self::$bufferSizeLimit,
                strlen($this->buffer)
            )
        );
    }
}
