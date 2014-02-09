<?php

abstract class WebsocketWorker extends WebsocketGeneric
{
    protected $pid;
    private $_handshakes = array();

    public function __construct($server, $master) {
        $this->_server = $server;
        $this->_services = array($this->getIdByConnection($master) => $master);

        $this->master = $master;
        $this->pid = posix_getpid();
    }

    protected function _onOpen($connectionId) {
        $this->_handshakes[$connectionId] = '';//отмечаем, что нужно сделать рукопожатие
    }

    protected function _onMessage($connectionId) {
        if (isset($this->_handshakes[$connectionId])) {
            if ($this->_handshakes[$connectionId]) {//если уже было получено рукопожатие от клиента
                return;//то до отправки ответа от сервера читать здесь пока ничего не надо
            }

            if (!$this->_handshake($connectionId)) {
                $this->close($connectionId);
            }
        } else {
            while (($data = $this->_decode($connectionId)) && mb_check_encoding($data['payload'], 'utf-8')) {//декодируем буфер (в нём может быть несколько сообщений)
                $this->onMessage($connectionId, $data);//вызываем пользовательский сценарий
            }
        }
    }

    protected function _onService($connectionId, $data) {
        $this->onMasterMessage($data);
    }

    protected function close($connectionId) {
        parent::close($connectionId);

        if (isset($this->_handshakes[$connectionId])) {
            unset($this->_handshakes[$connectionId]);
        } else {
            $this->onClose($connectionId);//вызываем пользовательский сценарий
        }
    }

    protected function sendToClient($connectionId, $data) {
        if (!isset($this->_handshakes[$connectionId])) {
            $this->_write($connectionId, $this->_encode($data));
        }
    }

    protected function sendToMaster($data) {
        $this->_write($this->master, $data, self::SOCKET_MESSAGE_DELIMITER);
    }

    protected function _handshake($connectionId) {
        //считываем загаловки из соединения
        if (!strpos($this->_read[$connectionId], "\r\n\r\n")) {
            return true;
        }

        preg_match("/Sec-WebSocket-Key: (.*)\r\n/", $this->_read[$connectionId], $match);

        if (empty($match[1])) {
            return false;
        }

        $this->_read[$connectionId] = '';

        //отправляем заголовок согласно протоколу вебсокета
        $SecWebSocketAccept = base64_encode(pack('H*', sha1($match[1] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept:$SecWebSocketAccept\r\n\r\n";

        $this->_write($connectionId, $upgrade);
        unset($this->_handshakes[$connectionId]);

        $this->onOpen($connectionId);

        return true;
    }

    protected function _encode($payload, $type = 'text', $masked = false)
    {
        $frameHead = array();
        $payloadLength = strlen($payload);

        switch ($type) {
            case 'text':
                // first byte indicates FIN, Text-Frame (10000001):
                $frameHead[0] = 129;
                break;

            case 'close':
                // first byte indicates FIN, Close Frame(10001000):
                $frameHead[0] = 136;
                break;

            case 'ping':
                // first byte indicates FIN, Ping frame (10001001):
                $frameHead[0] = 137;
                break;

            case 'pong':
                // first byte indicates FIN, Pong frame (10001010):
                $frameHead[0] = 138;
                break;
        }

        // set mask and payload length (using 1, 3 or 9 bytes)
        if ($payloadLength > 65535) {
            $payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
            $frameHead[1] = $masked ? 255 : 127;
            for ($i = 0; $i < 8; $i++) {
                $frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
            }
        } elseif ($payloadLength > 125) {
            $payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);
            $frameHead[1] = $masked ? 254 : 126;
            $frameHead[2] = bindec($payloadLengthBin[0]);
            $frameHead[3] = bindec($payloadLengthBin[1]);
        } else {
            $frameHead[1] = $masked ? $payloadLength + 128 : $payloadLength;
        }

        // convert frame-head to string:
        foreach (array_keys($frameHead) as $i) {
            $frameHead[$i] = chr($frameHead[$i]);
        }
        if ($masked) {
            // generate a random mask:
            $mask = array();
            for ($i = 0; $i < 4; $i++) {
                $mask[$i] = chr(rand(0, 255));
            }

            $frameHead = array_merge($frameHead, $mask);
        }
        $frame = implode('', $frameHead);

        // append payload to frame:
        for ($i = 0; $i < $payloadLength; $i++) {
            $frame .= $masked ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
        }

        return $frame;
    }

    protected function _decode($connect)
    {
        $data = $this->_read[$connect];

        $unmaskedPayload = '';
        $decodedData = array();

        // estimate frame type:
        $firstByteBinary = sprintf('%08b', ord($data[0]));
        $secondByteBinary = sprintf('%08b', ord($data[1]));
        $opcode = bindec(substr($firstByteBinary, 4, 4));
        $isMasked = $secondByteBinary[0] == '1';
        $payloadLength = ord($data[1]) & 127;

        switch ($opcode) {
            // text frame:
            case 1:
                $decodedData['type'] = 'text';
                break;

            case 2:
                $decodedData['type'] = 'binary';
                break;

            // connection close frame:
            case 8:
                $decodedData['type'] = 'close';
                break;

            // ping frame:
            case 9:
                $decodedData['type'] = 'ping';
                break;

            // pong frame:
            case 10:
                $decodedData['type'] = 'pong';
                break;

            default:
                $decodedData['type'] = '';
        }

        if ($payloadLength === 126) {
            $mask = substr($data, 4, 4);
            $payloadOffset = 8;
            $dataLength = bindec(sprintf('%08b', ord($data[2])) . sprintf('%08b', ord($data[3]))) + $payloadOffset;
        } elseif ($payloadLength === 127) {
            $mask = substr($data, 10, 4);
            $payloadOffset = 14;
            for ($tmp = '', $i = 0; $i < 8; $i++) {
                $tmp .= sprintf('%08b', ord($data[$i + 2]));
            }
            $dataLength = bindec($tmp) + $payloadOffset;
        } else {
            $mask = substr($data, 2, 4);
            $payloadOffset = 6;
            $dataLength = $payloadLength + $payloadOffset;
        }

        if (strlen($data) < $dataLength) {
            return false;
        } else {
            $this->_read[$connect] = substr($data, $dataLength);
        }

        if ($isMasked) {
            for ($i = $payloadOffset; $i < $dataLength; $i++) {
                $j = $i - $payloadOffset;
                if (isset($data[$i])) {
                    $unmaskedPayload .= $data[$i] ^ $mask[$j % 4];
                }
            }
            $decodedData['payload'] = $unmaskedPayload;
        } else {
            $payloadOffset = $payloadOffset - 4;
            $decodedData['payload'] = substr($data, $payloadOffset, $dataLength - $payloadOffset);
        }

        return $decodedData;
    }

    abstract protected function onMessage($connectionId, $data);

    abstract protected function onOpen($connectionId);

    abstract protected function onClose($connectionId);

    abstract protected function onMasterMessage($data);
}