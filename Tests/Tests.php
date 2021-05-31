<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Socket\SocketType;
use xPaw\SourceQuery\Socket\TestableSocket;

final class Tests extends TestCase
{
    /**
     * @var TestableSocket
     */
    private TestableSocket $socket;

    /**
     * @var SourceQuery
     */
    private SourceQuery $sourceQuery;

    /**
     * @throws InvalidArgumentException
     */
    public function setUp(): void
    {
        $this->socket = new TestableSocket(SocketType::SOURCE);
        $this->sourceQuery = new SourceQuery($this->socket);
        $this->sourceQuery->connect('', 2);
    }

    /**
     * tearDown
     */
    public function tearDown(): void
    {
        $this->sourceQuery->disconnect();

        unset($this->socket, $this->sourceQuery);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function testInvalidTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $SourceQuery = new SourceQuery($this->socket);
        $SourceQuery->connect('', 2, -1);
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetInfo(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->disconnect();
        $this->sourceQuery->getInfo();
    }

    /**
     * @throws SocketException
     */
    public function testNotConnectedPing(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->disconnect();
        $this->sourceQuery->ping();
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetPlayers(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->disconnect();
        $this->sourceQuery->getPlayers();
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetRules(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->disconnect();
        $this->sourceQuery->getRules();
    }

    /**
     * @throws SocketException
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function testNotConnectedSetRconPassword(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->disconnect();
        $this->sourceQuery->setRconPassword('a');
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedRcon(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->disconnect();
        $this->sourceQuery->rcon('a');
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testRconWithoutPassword(): void
    {
        $this->expectException(SocketException::class);
        $this->sourceQuery->rcon('a');
    }

    /**
     * @param string $rawInput
     * @param array $expectedOutput
     *
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider infoProvider
     */
    public function testGetInfo(string $rawInput, array $expectedOutput): void
    {
        if (isset($expectedOutput[ 'IsMod' ])) {
            $this->socket = new TestableSocket(SocketType::GOLDSOURCE);
            $this->sourceQuery = new SourceQuery($this->socket);
            $this->sourceQuery->connect('', 2);
        }

        $this->socket->queue($rawInput);

        $realOutput = $this->sourceQuery->getInfo();

        self::assertEquals($expectedOutput, $realOutput);
    }

    /**
     * @return array
     *
     * @throws JsonException
     */
    public function infoProvider(): array
    {
        return $this->getData('Info', true);
    }

    /**
     * @param string $data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetInfo(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->socket->queue($data);

        $this->sourceQuery->getInfo();
    }

    /**
     * @param string $data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetChallengeViaPlayers(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->socket->queue($data);

        $this->sourceQuery->getPlayers();
    }

    /**
     * @param string $data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetPlayersAfterCorrectChallenge(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->socket->queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $this->socket->queue($data);

        $this->sourceQuery->getPlayers();
    }

    /**
     * @param string $data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetRulesAfterCorrectChallenge(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->socket->queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $this->socket->queue($data);

        $this->sourceQuery->getRules();
    }

    /**
     * @return string[][]
     */
    public function badPacketProvider(): array
    {
        return
        [
            [ '' ],
            [ "\xff\xff\xff\xff" ], // No type.
            [ "\xff\xff\xff\xff\x49" ], // Correct type, but no data after.
            [ "\xff\xff\xff\xff\x6D" ], // Old info packet, but tests are done for source.
            [ "\xff\xff\xff\xff\x11" ], // Wrong type.
            [ "\x11\x11\x11\x11" ], // Wrong header.
            [ "\xff" ], // Should be 4 bytes, but it's 1.
        ];
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testGetChallengeTwice(): void
    {
        $this->socket->queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $this->socket->queue("\xFF\xFF\xFF\xFF\x45\x01\x00ayy\x00lmao\x00");
        self::assertEquals([ 'ayy' => 'lmao' ], $this->sourceQuery->getRules());

        $this->socket->queue("\xFF\xFF\xFF\xFF\x45\x01\x00wow\x00much\x00");
        self::assertEquals([ 'wow' => 'much' ], $this->sourceQuery->getRules());
    }

    /**
     * @param string[] $rawInput
     * @param array $expectedOutput
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider rulesProvider
     */
    public function testGetRules(array $rawInput, array $expectedOutput): void
    {
        $data = hex2bin('ffffffff4104fce20e');

        if (!$data) {
            throw new InvalidPacketException('Bad packet data');
        }

        $this->socket->queue($data); // Challenge.

        foreach ($rawInput as $packet) {
            $data = hex2bin($packet);

            if (!$data) {
                throw new InvalidPacketException('Bad packet data');
            }

            $this->socket->queue($data);
        }

        $realOutput = $this->sourceQuery->getRules();

        self::assertEquals($expectedOutput, $realOutput);
    }

    /**
     * @return array
     *
     * @throws JsonException
     */
    public function rulesProvider(): array
    {
        return $this->getData('Rules');
    }

    /**
     * @param string[] $rawInput
     * @param array $expectedOutput
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider playersProvider
     */
    public function testGetPlayers(array $rawInput, array $expectedOutput): void
    {
        $data = hex2bin('ffffffff4104fce20e');

        if (!$data) {
            throw new InvalidPacketException('Bad packet data');
        }

        $this->socket->queue($data); // Challenge.

        foreach ($rawInput as $packet) {
            $data = hex2bin($packet);

            if (!$data) {
                throw new InvalidPacketException('Bad packet data');
            }

            $this->socket->queue($data);
        }

        $realOutput = $this->sourceQuery->getPlayers();

        self::assertEquals($expectedOutput, $realOutput);
    }

    /**
     * @return array
     *
     * @throws JsonException
     */
    public function playersProvider(): array
    {
        return $this->getData('Players');
    }

    /**
     * @throws SocketException
     */
    public function testPing(): void
    {
        $this->socket->queue("\xFF\xFF\xFF\xFF\x6A\x00");
        self::assertTrue($this->sourceQuery->ping());

        $this->socket->queue("\xFF\xFF\xFF\xFF\xEE");
        self::assertFalse($this->sourceQuery->ping());
    }

    /**
     * @param string $path
     * @param bool $hexToBin
     *
     * @return array
     *
     * @throws JsonException
     */
    private function getData(string $path, bool $hexToBin = false): array
    {
        $dataProvider = [];

        $files = glob(__DIR__ . '/' . $path . '/*.raw', GLOB_ERR);

        if (!$files) {
            throw new RuntimeException('Could not load test data.');
        }

        foreach ($files as $file) {
            if ($hexToBin) {
                $content = file_get_contents($file);

                if (!$content) {
                    throw new RuntimeException('Could not load test data.');
                }

                $content = hex2bin(trim($content));
            } else {
                $content = file($file, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES);
            }

            $jsonContent = file_get_contents(
                str_replace('.raw', '.json', $file)
            );

            if (!$jsonContent) {
                throw new RuntimeException('Could not load test data.');
            }

            $dataProvider[] =
                [
                    $content,
                    json_decode(
                        $jsonContent,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    )
                ];
        }

        return $dataProvider;
    }
}
