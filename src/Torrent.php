<?php

    namespace Coco\torrent;

    class Torrent
    {
        protected \Arokettu\Torrent\TorrentFile $torrentClient;

        public function __construct(string $path)
        {
            $this->torrentClient = \Arokettu\Torrent\TorrentFile::load($path);
        }

        /**
         * @return \Arokettu\Torrent\TorrentFile
         */
        public function getTorrentClient(): \Arokettu\Torrent\TorrentFile
        {
            return $this->torrentClient;
        }

        /**
         * 计算并返回详细的信息数组
         *
         * @return array
         */
        public function getDetailedInfo(): array
        {
            $parsedArray = $this->torrentClient->getRawData()->toArray();

            $detailed = [];

            // 基本信息
            $detailed['basic'] = [
                [
                    'name'  => 'Announce',
                    'value' => $parsedArray['announce'] ?? '未知',
                ],
                [
                    'name'  => 'Created By',
                    'value' => $parsedArray['created by'] ?? '未知',
                ],
                [
                    'name'  => 'Creation Date',
                    'value' => $this->formatTimestamp($parsedArray['creation date'] ?? 0),
                ],
                [
                    'name'  => 'Encoding',
                    'value' => $parsedArray['encoding'] ?? '未知',
                ],
            ];

            // Info 部分
            $info                = $parsedArray['info'] ?? [];
            $detailed['basic'][] = [
                'name'  => 'Name',
                'value' => $info['name'] ?? '未知',
            ];
            $detailed['basic'][] = [
                'name'  => 'Piece Length',
                'value' => $this->formatSize($info['piece length'] ?? 0),
            ];

            // 计算 infohash
            $infoHash            = $this->calculateInfoHash($info);
            $detailed['basic'][] = [
                'name'  => 'Info Hash',
                'value' => strtoupper($infoHash),
            ];

            // 计算磁力链接
            $magnet              = $this->generateMagnetLink($infoHash, $info['name'] ?? '未知');
            $detailed['basic'][] = [
                'name'  => 'Magnet Link',
                'value' => $magnet,
            ];

            // Tracker 列表（扁平化 announce-list）
            $trackers     = [];
            $announceList = $parsedArray['announce-list'] ?? [];
            foreach ($announceList as $tier => $list)
            {
                foreach ($list as $url)
                {
                    $trackers[] = [
                        'tier' => $tier + 1,
                        'url'  => $url,
                    ];
                }
            }
            $detailed['trackers'] = $trackers;

            // 文件列表 + 计算总大小和数量
            $files     = $info['files'] ?? [];
            $fileList  = [];
            $totalSize = 0;
            $fileCount = 0;
            foreach ($files as $index => $file)
            {
                $fileSize  = $file['length'] ?? 0;
                $totalSize += $fileSize;
                $fileCount++;
                $fileList[] = [
                    'index' => $index + 1,
                    'name'  => implode('/', $file['path'] ?? []),
                    'size'  => $this->formatSize($fileSize),
                ];
            }
            $detailed['files'] = $fileList;

            // 额外计算信息
            $detailed['basic'][] = [
                'name'  => '文件数量',
                'value' => $fileCount,
            ];
            $detailed['basic'][] = [
                'name'  => '总大小',
                'value' => $this->formatSize($totalSize),
            ];

            // Nodes（DHT 节点，如果有）
            $nodes             = $parsedArray['nodes'] ?? [];
            $detailed['nodes'] = $nodes;

            return $detailed;
        }

        /**
         * 计算 info 字典的 SHA1 hash（infohash）
         *
         * @param array $info
         *
         * @return string 40位十六进制 hash
         */
        protected function calculateInfoHash(array $info): string
        {
            if (empty($info))
            {
                return '0000000000000000000000000000000000000000'; // 默认空 hash
            }

            // 强制排序字典键
            ksort($info, SORT_STRING);

            // BEncode 编码
            $encoded = $this->bencode($info);

            // SHA1 计算
            return sha1($encoded);
        }

        /**
         * 生成磁力链接
         *
         * @param string $infoHash
         * @param string $name
         *
         * @return string
         */
        protected function generateMagnetLink(string $infoHash, string $name): string
        {
            if (empty($infoHash))
            {
                return '无效磁力链接（缺少 infohash）';
            }

            $magnet = 'magnet:?xt=urn:btih:' . strtoupper($infoHash);
            if ($name)
            {
                $magnet .= '&dn=' . urlencode($name);
            }

            // 添加 Tracker（可选，从 announce-list 提取前 5 个以免太长）
            $trackers = array_slice($this->flattenTrackers(), 0, 5);
            foreach ($trackers as $tr)
            {
                $magnet .= '&tr=' . urlencode($tr);
            }

            return $magnet;
        }

        /**
         * 扁平化 announce-list
         *
         * @return array
         */
        protected function flattenTrackers(): array
        {
            $flat         = [];
            $announceList = $parsedArray['announce-list'] ?? [];
            foreach ($announceList as $list)
            {
                foreach ($list as $url)
                {
                    $flat[] = $url;
                }
            }

            return $flat;
        }

        /**
         * BEncode 编码函数
         *
         * @param mixed $data
         *
         * @return string
         */
        protected function bencode(mixed $data): string
        {
            if (is_int($data))
            {
                return 'i' . $data . 'e';
            }

            if (is_string($data))
            {
                return strlen($data) . ':' . $data;
            }

            if (is_array($data))
            {
                if (empty($data) || array_keys($data) === range(0, count($data) - 1))
                {
                    // 列表
                    $encoded = 'l';
                    foreach ($data as $value)
                    {
                        $encoded .= $this->bencode($value);
                    }

                    return $encoded . 'e';
                }
                else
                {
                    // 字典（已排序）
                    $encoded = 'd';
                    foreach ($data as $key => $value)
                    {
                        $encoded .= $this->bencode((string)$key) . $this->bencode($value);
                    }

                    return $encoded . 'e';
                }
            }

            return '';
        }

        /**
         * 格式化大小
         *
         * @param int $size
         *
         * @return string
         */
        protected function formatSize(int $size): string
        {
            $units = [
                'B',
                'KB',
                'MB',
                'GB',
                'TB',
            ];
            $i     = 0;
            while ($size >= 1024 && $i < count($units) - 1)
            {
                $size /= 1024;
                $i++;
            }

            return round($size, 2) . ' ' . $units[$i];
        }

        /**
         * 格式化时间戳
         *
         * @param int $timestamp
         *
         * @return string
         */
        protected function formatTimestamp(int $timestamp): string
        {
            return $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : '未知';
        }
    }
