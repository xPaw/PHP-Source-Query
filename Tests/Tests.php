<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;
use xPaw\SourceQuery\Rcon\TestableRcon;
use xPaw\SourceQuery\Socket\SocketType;
use xPaw\SourceQuery\Socket\TestableSocket;
use xPaw\SourceQuery\SourceQuery;

/**
 * @internal
 * @covers \xPaw\SourceQuery\SourceQuery
 *
 * @psalm-suppress PropertyNotSetInConstructor
 */
final class Tests extends TestCase
{
    /**
     * @throws InvalidArgumentException
     */
    public function testInvalidTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->connect('', 2, -1);
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetInfo(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->getInfo();
    }

    /**
     * @throws InvalidArgumentException
     * @throws SocketException
     */
    public function testNotConnectedPing(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->ping();
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetPlayers(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->getPlayers();
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetRules(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->getRules();
    }

    /**
     * @throws InvalidArgumentException
     * @throws SocketException
     * @throws AuthenticationException
     */
    public function testNotConnectedSetRconPassword(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->setRconPassword('a');
    }

    /**
     * @throws InvalidArgumentException
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedRcon(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->disconnect();
        $sourceQuery->rcon('a');
    }

    /**
     * @throws InvalidArgumentException
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testRconWithoutPassword(): void
    {
        $this->expectException(SocketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $sourceQuery->rcon('a');
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider infoProvider
     */
    public function testGetInfo(string $rawInput, array $expectedOutput): void
    {
        $socketType = isset($expectedOutput['IsMod'])
            ? SocketType::GOLDSOURCE
            : SocketType::SOURCE;

        $socket = new TestableSocket($socketType);
        $sourceQuery = $this->create($socket);

        $socket->queue($rawInput);

        $realOutput = $sourceQuery->getInfo();

        self::assertSame($expectedOutput, $realOutput);
    }

    /**
     * @throws JsonException
     */
    public function infoProvider(): array
    {
        return $this->getData('Info', true);
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetInfo(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $socket->queue($data);

        $sourceQuery->getInfo();
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetChallengeViaPlayers(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $socket->queue($data);

        $sourceQuery->getPlayers();
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetPlayersAfterCorrectChallenge(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $socket->queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $socket->queue($data);

        $sourceQuery->getPlayers();
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider badPacketProvider
     */
    public function testBadGetRulesAfterCorrectChallenge(string $data): void
    {
        $this->expectException(InvalidPacketException::class);
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $socket->queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $socket->queue($data);

        $sourceQuery->getRules();
    }

    /**
     * @return string[][]
     */
    public function badPacketProvider(): array
    {
        return
        [
            [''],
            ["\xff\xff\xff\xff"], // No type.
            ["\xff\xff\xff\xff\x49"], // Correct type, but no data after.
            ["\xff\xff\xff\xff\x6D"], // Old info packet, but tests are done for source.
            ["\xff\xff\xff\xff\x11"], // Wrong type.
            ["\x11\x11\x11\x11"], // Wrong header.
            ["\xff"], // Should be 4 bytes, but it's 1.
        ];
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testGetChallengeTwice(): void
    {
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);
        $socket->queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $socket->queue("\xFF\xFF\xFF\xFF\x45\x01\x00ayy\x00lmao\x00");
        self::assertSame(['ayy' => 'lmao'], $sourceQuery->getRules());

        $socket->queue("\xFF\xFF\xFF\xFF\x45\x01\x00wow\x00much\x00");
        self::assertSame(['wow' => 'much'], $sourceQuery->getRules());
    }

    /**
     * @param string[] $rawInput
     *
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider rulesProvider
     */
    public function testGetRules(array $rawInput, array $expectedOutput): void
    {
        $sourceQuery = $this->putDataOnSocket($rawInput);

        $realOutput = $sourceQuery->getRules();

        self::assertSame($expectedOutput, $realOutput);
    }

    /**
     * @throws JsonException
     */
    public function rulesProvider(): array
    {
        return $this->getData('Rules');
    }

    /**
     * @param string[] $rawInput
     *
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider playersProvider
     */
    public function testGetPlayers(array $rawInput, array $expectedOutput): void
    {
        $sourceQuery = $this->putDataOnSocket($rawInput);

        $realOutput = $sourceQuery->getPlayers();

        self::assertSame($expectedOutput, $realOutput);
    }

    /**
     * @throws JsonException
     */
    public function playersProvider(): array
    {
        return $this->getData('Players');
    }

    /**
     * @throws InvalidArgumentException
     * @throws SocketException
     */
    public function testPing(): void
    {
        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);

        $socket->queue("\xFF\xFF\xFF\xFF\x6A\x00");
        self::assertTrue($sourceQuery->ping());

        $socket->queue("\xFF\xFF\xFF\xFF\xEE");
        self::assertFalse($sourceQuery->ping());
    }

    /**
     * @throws InvalidArgumentException
     */
    private function create(TestableSocket $socket): SourceQuery
    {
        $rcon = new TestableRcon();
        $sourceQuery = new SourceQuery($socket, $rcon);
        $sourceQuery->connect('', 2);

        return $sourceQuery;
    }

    /**
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
                    ),
                ];
        }

        return $dataProvider;
    }

    /**
     * @param string[] $rawInput
     *
     * @throws InvalidArgumentException
     * @throws InvalidPacketException
     */
    private function putDataOnSocket(array $rawInput): SourceQuery
    {
        $data = hex2bin('ffffffff4104fce20e');

        if (!$data) {
            throw new InvalidPacketException('Bad packet data');
        }

        $socket = new TestableSocket();
        $sourceQuery = $this->create($socket);

        $socket->queue($data); // Challenge.

        foreach ($rawInput as $packet) {
            $data = hex2bin($packet);

            if (!$data) {
                throw new InvalidPacketException('Bad packet data');
            }

            $socket->queue($data);
        }

        return $sourceQuery;
    }
}
