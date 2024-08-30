<?php

    namespace Coco\torrent;

    use Exception;

class Torrent
{
    const timeout = 30;

    private string $encoding = 'utf-8';

    public function __construct($data = null, $meta = [], $piece_length = 256, $encoding = 'utf-8')
    {
        $this->encoding = $encoding;

        if (is_null($data)) {
            return false;
        }
        if ($piece_length < 32 || $piece_length > 4096) {
            return self::throw_error(new Exception('Invalid piece length, must be between 32 and 4096'));
        }
        if (is_string($meta)) {
            $meta = ['announce' => $meta];
        }
        if ($this->build($data, $piece_length * 1024)) {
            $this->touch();
        } else {
            $meta = array_merge($meta, static::decode($data));
        }
        foreach ($meta as $key => $value) {
            $this->{trim($key)} = $value;
        }
    }

    public function getArrayInfo(): array
    {
        $file_arr = [];

        $files = $this->torrent_files($this->name(), $this->content(), $this->encoding);

        $magnet = $this->magnet(false);

        $magnet = substr($magnet, 0, stripos($magnet, "xl="));

        foreach ($files as $value) {
            $file_arr[] = [
                "name" => $value['name'],
                "size" => $value['size'],
            ];
        }

        $data = [];

        $data[] = [
            "id"    => "private",
            "title" => "是否私有",
            "value" => $this->is_private() ? 'yes' : 'no',
        ];
        $data[] = [
            "id"    => "name",
            "title" => "种子名称",
            "value" => static::char_encoding($this->name(), $this->encoding),
        ];
        $data[] = [
            "id"    => "hash",
            "title" => "种子哈希",
            "value" => $this->hash_info(),
        ];
        $data[] = [
            "id"    => "magnet",
            "title" => "磁力链接",
            "value" => $magnet,
        ];
        $data[] = [
            "id"    => "number",
            "title" => "文件数目",
            "value" => count($file_arr),
        ];
        $data[] = [
            "id"    => "size",
            "title" => "文件大小",
            "value" => $this->size(2),
        ];
        $data[] = [
            "id"    => "piece",
            "title" => "分块大小",
            "value" => Torrent::format($this->piece_length()),
        ];
        $data[] = [
            "id"    => "date",
            "title" => "发布时间",
            "value" => date('Y-m-d H:i:s', $this->creation_date()),
        ];
        $data[] = [
            "id"    => "publisher",
            "title" => "发布人员",
            "value" => static::char_encoding($this->publisher(), $this->encoding),
        ];
        $data[] = [
            "id"    => "comment",
            "title" => "描述内容",
            "value" => static::char_encoding($this->comment(), $this->encoding),
        ];

        $data[] = [
            "id"    => "announce",
            "title" => "服务器链",
            "value" => static::untier($this->announce()),
        ];
        $data[] = [
            "id"    => "files",
            "title" => "文件列表",
            "value" => $file_arr,
        ];

        return $data;
    }

    /*-----------------------------------------------------------------------*/
    public function __toString()
    {
        return static::encode($this);
    }

    public function announce($announce = null)
    {
        if (is_null($announce)) {
            return !isset($this->{'announce-list'}) ? $this->announce ?? null : $this->{'announce-list'};
        }
        $this->touch();
        if (is_string($announce) && isset($this->announce)) {
            return $this->{'announce-list'} = self::announce_list(isset($this->{'announce-list'}) ? $this->{'announce-list'} : $this->announce, $announce);
        }
        unset($this->{'announce-list'});
        if (is_array($announce) || is_object($announce)) {
            if (($this->announce = self::first_announce($announce)) && count($announce) > 1) {
                return $this->{'announce-list'} = self::announce_list($announce);
            } else {
                return $this->announce;
            }
        }
        if (!isset($this->announce) && $announce) {
            return $this->announce = (string)$announce;
        }
        unset($this->announce);
    }

    public function creation_date($timestamp = null)
    {
        return is_null($timestamp) ? $this->{'creation date'} ?? null : $this->touch($this->{'creation date'} = (int)$timestamp);
    }

    public function comment($comment = null)
    {
        return is_null($comment) ? $this->comment ?? null : $this->touch($this->comment = (string)$comment);
    }

    public function name($name = null)
    {
        return is_null($name) ? $this->info['name'] ?? null : $this->touch($this->info['name'] = (string)$name);
    }

    public function is_private($private = null)
    {
        return is_null($private) ? !empty($this->info['private']) : $this->touch($this->info['private'] = $private ? 1 : 0);
    }

    public function source($source = null)
    {
        return is_null($source) ? $this->info['source'] ?? null : $this->touch($this->info['source'] = (string)$source);
    }

    public function url_list($urls = null)
    {
        return is_null($urls) ? $this->{'url-list'} ?? null : $this->touch($this->{'url-list'} = is_string($urls) ? $urls : (array)$urls);
    }

    public function httpseeds($urls = null)
    {
        return is_null($urls) ? $this->httpseeds ?? null : $this->touch($this->httpseeds = (array)$urls);
    }

    public function piece_length()
    {
        return $this->info['piece length'] ?? null;
    }

    public function publisher()
    {
        return $this->info['publisher'] ?? null;
    }

    public function hash_info()
    {
        return isset($this->info) ? sha1(self::encode($this->info)) : null;
    }

    public function content($precision = null)
    {
        $files = [];
        if (isset($this->info['files']) && is_array($this->info['files'])) {
            foreach ($this->info['files'] as $file) {
                $files[self::path($file['path'], $this->info['name'])] = $precision ? self::format($file['length'], $precision) : $file['length'];
            }
        } elseif (isset($this->info['name'])) {
            $files[$this->info['name']] = $precision ? self::format($this->info['length'], $precision) : $this->info['length'];
        }

        return $files;
    }

    public function offset()
    {
        $files = [];
        $size  = 0;
        if (isset($this->info['files']) && is_array($this->info['files'])) {
            foreach ($this->info['files'] as $file) {
                $files[self::path($file['path'], $this->info['name'])] = [
                    'startpiece' => floor($size / $this->info['piece length']),
                    'offset'     => fmod($size, $this->info['piece length']),
                    'size'       => $size += $file['length'],
                    'endpiece'   => floor($size / $this->info['piece length']),
                ];
            }
        } elseif (isset($this->info['name'])) {
            $files[$this->info['name']] = [
                'startpiece' => 0,
                'offset'     => 0,
                'size'       => $this->info['length'],
                'endpiece'   => floor($this->info['length'] / $this->info['piece length']),
            ];
        }

        return $files;
    }

    public function size($precision = null)
    {
        $size = 0;
        if (isset($this->info['files']) && is_array($this->info['files'])) {
            foreach ($this->info['files'] as $file) {
                $size += $file['length'];
            }
        } elseif (isset($this->info['name'])) {
            $size = $this->info['length'];
        }

        return is_null($precision) ? $size : self::format($size, $precision);
    }

    public function save($filename = null)
    {
        return file_put_contents(is_null($filename) ? $this->info['name'] . '.torrent' : $filename, static::encode($this));
    }

    public function send($filename = null)
    {
        $data = static::encode($this);
        header('Content-type: application/x-bittorrent');
        header('Content-Length: ' . strlen($data));
        header('Content-Disposition: attachment; filename="' . (is_null($filename) ? $this->info['name'] . '.torrent' : $filename) . '"');
        exit($data);
    }

    public function magnet($html = true)
    {
        $ampersand = $html ? '&amp;' : '&';

        return sprintf('magnet:?xt=urn:btih:%2$s%1$sdn=%3$s%1$sxl=%4$d%1$str=%5$s', $ampersand, $this->hash_info(), urlencode($this->name()), $this->size(), implode($ampersand . 'tr=', self::untier($this->announce())));
    }

    /*-----------------------------------------------------------------------*/

    public static function format($size, $precision = 2)
    {
        $units = [
            'B',
            'KB',
            'MB',
            'GB',
            'TB',
        ];
        while (($next = next($units)) && $size > 1024) {
            $size /= 1024;
        }

        return round($size, $precision) . ' ' . ($next ? prev($units) : end($units));
    }

    public static function filesize($file)
    {
        if (is_file($file)) {
            return (double)sprintf('%u', @filesize($file));
        } elseif ($content_length = preg_grep($pattern = '#^Content-Length:\s+(\d+)$#i', (array)@get_headers($file))) {
            return (int)preg_replace($pattern, '$1', reset($content_length));
        }
    }

    public static function fopen($file, $size = null)
    {
        if ((is_null($size) ? self::filesize($file) : $size) <= 2 * pow(1024, 3)) {
            return fopen($file, 'r');
        } elseif (PHP_OS != 'Linux') {
            return self::throw_error(new Exception('File size is greater than 2GB. This is only supported under Linux'));
        } elseif (!is_readable($file)) {
            return false;
        } else {
            return popen('cat ' . escapeshellarg(realpath($file)), 'r');
        }
    }

    public static function scandir($dir)
    {
        $paths = [];
        foreach (scandir($dir) as $item) {
            if ($item != '.' && $item != '..') {
                if (is_dir($path = realpath($dir . DIRECTORY_SEPARATOR . $item))) {
                    $paths = array_merge(self::scandir($path), $paths);
                } else {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    public static function is_url($url)
    {
        return preg_match('#^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$#i', $url);
    }

    public static function url_exists($url)
    {
        return self::is_url($url) && (bool)self::filesize($url);
    }

    // @see https://github.com/adriengibrat/torrent-rw/issues/32
    // @see https://github.com/adriengibrat/torrent-rw/pull/17
    public static function is_torrent($file, $timeout = self::timeout): bool
    {
        $start = self::file_get_contents($file, $timeout, 0, 11);

        // Define exact matches in an array
        $exactMatches = [
            'd8:announce',
            'd10:created',
            'd13:creatio',
            'd13:announc',
            'd12:_info_l',
        ];

        // Check if $start is an exact match or starts with specific prefixes
        return $start && (in_array($start, $exactMatches, true) || str_starts_with($start, 'd7:comment') || str_starts_with($start, 'd4:info') || str_starts_with($start, 'd9:'));
    }

    public static function file_get_contents($file, $timeout = self::timeout, $offset = null, $length = null): bool|string
    {
        if (is_file($file) || ini_get('allow_url_fopen')) {
            $context = !is_file($file) && $timeout ? stream_context_create(['http' => ['timeout' => $timeout]]) : null;

            return !is_null($offset) ? $length ? @file_get_contents($file, false, $context, $offset, $length) : @file_get_contents($file, false, $context, $offset) : @file_get_contents($file, false, $context);
        } elseif (!function_exists('curl_init')) {
            return self::throw_error(new Exception('Install CURL or enable "allow_url_fopen"'));
        }

        $handle = curl_init($file);
        if ($timeout) {
            curl_setopt($handle, CURLOPT_TIMEOUT, $timeout);
        }
        if ($offset || $length) {
            curl_setopt($handle, CURLOPT_RANGE, $offset . '-' . ($length ? $offset + $length - 1 : null));
        }
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, 1);
        $content = curl_exec($handle);
        $size    = curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($handle);

        return ($offset && $size == -1) || ($length && $length != $size) ? $length ? substr($content, $offset, $length) : substr($content, $offset) : $content;
    }

    public static function untier($announces)
    {
        $list = [];
        foreach ((array)$announces as $tier) {
            is_array($tier) ? $list = array_merge($list, self::untier($tier)) : array_push($list, $tier);
        }

        return $list;
    }

    public static function encode($mixed)
    {
        switch (gettype($mixed)) {
            case 'integer':
            case 'double':
                return self::encode_integer($mixed);
            case 'object':
                $mixed = get_object_vars($mixed);
            case 'array':
                return self::encode_array($mixed);
            default:
                return self::encode_string((string)$mixed);
        }
    }

    public static function char_encoding($content, $encoding = "UTF-8")
    {
        if (empty($encoding)) {
            return $content;
        }
        if (strtoupper($encoding) == "UTF-8") {
            return $content;
        } else {
            return iconv($encoding, "UTF-8", $content);
        }
    }

    /*-----------------------------------------------------------------------*/

    private function torrent_files($name, $fileInfo, $encoding = "UTF-8")
    {
        $arr = [];

        foreach ($fileInfo as $fileName => $fileSize) {
            if (!stristr($fileName, 'BitComet')) {
                $fileName = str_replace($name . DIRECTORY_SEPARATOR, "", $fileName);
                $fileName = static::char_encoding($fileName, $encoding);
                $arr[]    = [
                    'name' => $fileName,
                    'size' => Torrent::format($fileSize),
                ];
            }
        }

        return $arr;
    }

    private function build($data, $piece_length)
    {
        if (is_null($data)) {
            return false;
        } elseif (is_array($data) && self::is_list($data)) {
            return $this->info = $this->files($data, $piece_length);
        } elseif (is_dir($data)) {
            return $this->info = $this->folder($data, $piece_length);
        } elseif ((is_file($data) || self::url_exists($data)) && !self::is_torrent($data)) {
            return $this->info = $this->file($data, $piece_length);
        } else {
            return false;
        }
    }

    private function touch($void = null)
    {
        $this->{'creation date'} = time();

        return $void;
    }

    private function pieces($handle, $piece_length, $last = true)
    {
        static $piece, $length;
        if (empty($length)) {
            $length = $piece_length;
        }
        $pieces = null;
        while (!feof($handle)) {
            if (($length = strlen($piece .= fread($handle, $length))) == $piece_length) {
                $pieces .= self::pack($piece);
            } elseif (($length = $piece_length - $length) < 0) {
                return self::throw_error(new Exception('Invalid piece length!'));
            }
        }
        fclose($handle);

        return $pieces . ($last && $piece ? self::pack($piece) : null);
    }

    private function file($file, $piece_length)
    {
        if (!$handle = self::fopen($file, $size = self::filesize($file))) {
            return self::throw_error(new Exception('Failed to open file: "' . $file . '"'));
        }
        if (self::is_url($file)) {
            $this->url_list($file);
        }
        $path = self::path_explode($file);

        return [
            'length'       => $size,
            'name'         => end($path),
            'piece length' => $piece_length,
            'pieces'       => $this->pieces($handle, $piece_length),
        ];
    }

    private function files($files, $piece_length)
    {
        sort($files);
        usort($files, function ($a, $b) {
            return strrpos($a, DIRECTORY_SEPARATOR) - strrpos($b, DIRECTORY_SEPARATOR);
        });

        $first = reset($files);
        if (!self::is_url($first)) {
            $files = array_map('realpath', $files);
        } else {
            $this->url_list(dirname($first) . DIRECTORY_SEPARATOR);
        }

        $files_path = array_map([
            self::class,
            'path_explode',
        ], $files);
        $root       = array_intersect_assoc(...$files_path);

        $pieces     = '';
        $info_files = [];
        $count      = count($files) - 1;

        foreach ($files as $i => $file) {
            if (!$handle = self::fopen($file, $filesize = self::filesize($file))) {
                self::throw_error(new Exception('Failed to open file: "' . $file . '" discarded'));
            }

            $pieces       .= $this->pieces($handle, $piece_length, $count === $i);
            $info_files[] = [
                'length' => $filesize,
                'path'   => array_diff_assoc($files_path[$i], $root),
            ];
        }

        return [
            'files'        => $info_files,
            'name'         => end($root),
            'piece length' => $piece_length,
            'pieces'       => $pieces,
        ];
    }

    private function folder($dir, $piece_length)
    {
        return $this->files(self::scandir($dir), $piece_length);
    }

    /*-----------------------------------------------------------------------*/

    private static function throw_error(Exception $exception)
    {
        return throw  $exception;
    }

    private static function announce_list($announce, $merge = [])
    {
        return array_map(function ($a) {
            return (array)$a;
        }, array_merge((array)$announce, (array)$merge));
    }

    private static function first_announce($announce)
    {
        while (is_array($announce)) {
            $announce = reset($announce);
        }

        return $announce;
    }

    private static function pack(&$data)
    {
        return pack('H*', sha1($data)) . ($data = null);
    }

    private static function path($path, $folder)
    {
        array_unshift($path, $folder);

        return join(DIRECTORY_SEPARATOR, $path);
    }

    private static function path_explode($path)
    {
        return explode(DIRECTORY_SEPARATOR, $path);
    }

    private static function is_list($array)
    {
        foreach (array_keys($array) as $key) {
            if (!is_int($key)) {
                return false;
            }
        }

        return true;
    }

    private static function encode_string($string)
    {
        return strlen($string) . ':' . $string;
    }

    private static function encode_integer($integer)
    {
        return 'i' . $integer . 'e';
    }

    private static function encode_array($array)
    {
        if (self::is_list($array)) {
            $return = 'l';
            foreach ($array as $value) {
                $return .= self::encode($value);
            }
        } else {
            ksort($array, SORT_STRING);
            $return = 'd';
            foreach ($array as $key => $value) {
                $return .= self::encode(strval($key)) . self::encode($value);
            }
        }

        return $return . 'e';
    }

    private static function decode($string)
    {
        $data = is_file($string) || self::url_exists($string) ? self::file_get_contents($string) : $string;

        return (array)self::decode_data($data);
    }

    private static function decode_data(&$data)
    {
        switch (self::char($data)) {
            case 'i':
                $data = substr($data, 1);

                return self::decode_integer($data);
            case 'l':
                $data = substr($data, 1);

                return self::decode_list($data);
            case 'd':
                $data = substr($data, 1);

                return self::decode_dictionary($data);
            default:
                return self::decode_string($data);
        }
    }

    private static function decode_dictionary(&$data)
    {
        $dictionary = [];
        $previous   = null;
        while (($char = self::char($data)) != 'e') {
            if ($char === false) {
                return self::throw_error(new Exception('Unterminated dictionary'));
            }
            if (!ctype_digit($char)) {
                return self::throw_error(new Exception('Invalid dictionary key'));
            }
            $key = self::decode_string($data);
            if (isset($dictionary[$key])) {
                return self::throw_error(new Exception('Duplicate dictionary key'));
            }
            if ($key < $previous) {
                self::throw_error(new Exception('Missorted dictionary key'));
            }
            $dictionary[$key] = self::decode_data($data);
            $previous         = $key;
        }
        $data = substr($data, 1);

        return $dictionary;
    }

    private static function decode_list(&$data)
    {
        $list = [];
        while (($char = self::char($data)) != 'e') {
            if ($char === false) {
                return self::throw_error(new Exception('Unterminated list'));
            }
            $list[] = self::decode_data($data);
        }
        $data = substr($data, 1);

        return $list;
    }

    private static function decode_string(&$data)
    {
        if (self::char($data) === '0' && substr($data, 1, 1) != ':') {
            self::throw_error(new Exception('Invalid string length, leading zero'));
        }
        if (!$colon = @strpos($data, ':')) {
            return self::throw_error(new Exception('Invalid string length, colon not found'));
        }
        $length = intval(substr($data, 0, $colon));
        if ($length + $colon + 1 > strlen($data)) {
            return self::throw_error(new Exception('Invalid string, input too short for string length'));
        }
        $string = substr($data, $colon + 1, $length);
        $data   = substr($data, $colon + $length + 1);

        return $string;
    }

    private static function decode_integer(&$data)
    {
        $start = 0;
        $end   = strpos($data, 'e');
        if ($end === 0) {
            self::throw_error(new Exception('Empty integer'));
        }
        if (self::char($data) == '-') {
            $start++;
        }
        if (substr($data, $start, 1) == '0' && $end > $start + 1) {
            self::throw_error(new Exception('Leading zero in integer'));
        }
        if (!ctype_digit(substr($data, $start, $start ? $end - 1 : $end))) {
            self::throw_error(new Exception('Non-digit characters in integer'));
        }
        $integer = substr($data, 0, $end);
        $data    = substr($data, $end + 1);

        return (int)$integer;
    }

    private static function char($data)
    {
        return empty($data) ? false : substr($data, 0, 1);
    }
}
