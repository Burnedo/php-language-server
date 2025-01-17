<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Message;
use AdvancedJsonRpc\Message as MessageBody;
use Sabre\Event\{Loop, Emitter};

class ProtocolStreamReader extends Emitter implements ProtocolReader
{
    const PARSE_HEADERS = 1;
    const PARSE_BODY = 2;

    private $input;
    private $parsingMode = self::PARSE_HEADERS;
    private $buffer = '';
    private $headers = [];
    private $contentLength;

    /**
     * @param resource $input
     */
    public function __construct($input)
    {
        $this->input = $input;

        $this->on('close', function () {
            Loop\removeReadStream($this->input);
        });

        $logger = new StderrLogger();

        Loop\addReadStream($this->input, function () use ($logger) {
            if (feof($this->input)) {
                // If stream_select reported a status change for this stream,
                // but the stream is EOF, it means it was closed.
                $this->emit('close');
                return;
            }
            while (($c = fgetc($this->input)) !== false && $c !== '') {
                $this->buffer .= $c;
                switch ($this->parsingMode) {
                    case self::PARSE_HEADERS:
                        if ($this->buffer === "\r\n") {
                            $this->parsingMode = self::PARSE_BODY;
                            $this->contentLength = (int)$this->headers['Content-Length'];
                            $this->buffer = '';
                        } else if (substr($this->buffer, -2) === "\r\n") {
                            $parts = explode(':', $this->buffer);
                            $this->headers[$parts[0]] = trim($parts[1]);
                            $this->buffer = '';
                        }
                        break;
                    case self::PARSE_BODY:
                        if (strlen($this->buffer) === $this->contentLength) {
                            try {
                                $msg = new Message(MessageBody::parse($this->buffer), $this->headers);
                                $this->emit('message', [$msg]);
                            } catch (\Exception $e) {
                                $logger->warning($e->getMessage(), [
                                    'body' => $this->buffer,
                                ]);
                            }
                            $this->parsingMode = self::PARSE_HEADERS;
                            $this->headers = [];
                            $this->buffer = '';
                        }
                        break;
                }
            }
        });
    }
}
