<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use xPaw\SourceQuery\BaseSocket;
use xPaw\SourceQuery\SourceQuery;
use xPaw\SourceQuery\Buffer;
use xPaw\SourceQuery\Exception\AuthenticationException;
use xPaw\SourceQuery\Exception\InvalidArgumentException;
use xPaw\SourceQuery\Exception\InvalidPacketException;
use xPaw\SourceQuery\Exception\SocketException;

final class TestableSocket extends BaseSocket
{
    /**
     * @var SplQueue<string>
     */
    private SplQueue $PacketQueue;

    /**
     * TestableSocket constructor.
     */
    public function __construct()
    {
        $this->PacketQueue = new SplQueue();
        $this->PacketQueue->setIteratorMode(SplDoublyLinkedList::IT_MODE_DELETE);
    }

    /**
     * @param string $Data
     */
    public function Queue(string $Data): void
    {
        $this->PacketQueue->push($Data);
    }

    /**
     * Close.
     */
    public function Close(): void
    {
    }

    /**
     * @param string $Address
     * @param int $Port
     * @param int $Timeout
     * @param int $Engine
     */
    public function Open(string $Address, int $Port, int $Timeout, int $Engine): void
    {
        $this->Timeout = $Timeout;
        $this->Engine  = $Engine;
        $this->Port    = $Port;
        $this->Address = $Address;
    }

    /**
     * @param int $Header
     * @param string $String
     *
     * @return bool
     */
    public function Write(int $Header, string $String = ''): bool
    {
        return true;
    }

    /**
     * @param int $Length
     *
     * @return Buffer
     *
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function Read(int $Length = 1400): Buffer
    {
        $Buffer = new Buffer();
        $Buffer->Set($this->PacketQueue->shift());

        $this->ReadInternal($Buffer, $Length, [ $this, 'Sherlock' ]);

        return $Buffer;
    }

    /**
     * @param Buffer $Buffer
     *
     * @return bool
     *
     * @throws InvalidPacketException
     */
    public function Sherlock(Buffer $Buffer): bool
    {
        if ($this->PacketQueue->isEmpty()) {
            return false;
        }

        $Buffer->Set($this->PacketQueue->shift());

        return $Buffer->GetLong() === -2;
    }
}

final class Tests extends TestCase
{
    /**
     * @var TestableSocket $Socket
     */
    private TestableSocket $Socket;

    /**
     * @var SourceQuery $SourceQuery
     */
    private SourceQuery $SourceQuery;

    /**
     * @throws SocketException
     * @throws InvalidArgumentException
     */
    public function setUp(): void
    {
        $this->Socket = new TestableSocket();
        $this->SourceQuery = new SourceQuery($this->Socket);
        $this->SourceQuery->Connect('', 2);
    }

    /**
     * tearDown
     */
    public function tearDown(): void
    {
        $this->SourceQuery->Disconnect();

        unset($this->Socket, $this->SourceQuery);
    }

    /**
     * @throws InvalidArgumentException
     * @throws SocketException
     */
    public function testInvalidTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $SourceQuery = new SourceQuery();
        $SourceQuery->Connect('', 2, -1);
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetInfo(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Disconnect();
        $this->SourceQuery->GetInfo();
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedPing(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Disconnect();
        $this->SourceQuery->Ping();
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetPlayers(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Disconnect();
        $this->SourceQuery->GetPlayers();
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedGetRules(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Disconnect();
        $this->SourceQuery->GetRules();
    }

    /**
     * @throws SocketException
     * @throws AuthenticationException
     * @throws InvalidPacketException
     */
    public function testNotConnectedSetRconPassword(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Disconnect();
        $this->SourceQuery->SetRconPassword('a');
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testNotConnectedRcon(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Disconnect();
        $this->SourceQuery->Rcon('a');
    }

    /**
     * @throws AuthenticationException
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testRconWithoutPassword(): void
    {
        $this->expectException(SocketException::class);
        $this->SourceQuery->Rcon('a');
    }

    /**
     * @param string $RawInput
     * @param array $ExpectedOutput
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider InfoProvider
     */
    public function testGetInfo(string $RawInput, array $ExpectedOutput): void
    {
        if (isset($ExpectedOutput[ 'IsMod' ])) {
            $this->Socket->Engine = SourceQuery::GOLDSOURCE;
        }

        $this->Socket->Queue($RawInput);

        $RealOutput = $this->SourceQuery->GetInfo();

        self::assertEquals($ExpectedOutput, $RealOutput);
    }

    /**
     * @return array
     *
     * @throws JsonException
     */
    public function InfoProvider(): array
    {
        $DataProvider = [];

        $Files = glob(__DIR__ . '/Info/*.raw', GLOB_ERR);

        foreach ($Files as $File) {
            $DataProvider[] =
            [
                hex2bin(trim(file_get_contents($File))),
                json_decode(
                    file_get_contents(
                        str_replace('.raw', '.json', $File)
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            ];
        }

        return $DataProvider;
    }

    /**
     * @param string $Data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider BadPacketProvider
     */
    public function testBadGetInfo(string $Data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->Socket->Queue($Data);

        $this->SourceQuery->GetInfo();
    }

    /**
     * @param string $Data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider BadPacketProvider
     */
    public function testBadGetChallengeViaPlayers(string $Data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->Socket->Queue($Data);

        $this->SourceQuery->GetPlayers();
    }

    /**
     * @param string $Data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider BadPacketProvider
     */
    public function testBadGetPlayersAfterCorrectChallenge(string $Data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->Socket->Queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $this->Socket->Queue($Data);

        $this->SourceQuery->GetPlayers();
    }

    /**
     * @param string $Data
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider BadPacketProvider
     */
    public function testBadGetRulesAfterCorrectChallenge(string $Data): void
    {
        $this->expectException(InvalidPacketException::class);
        $this->Socket->Queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $this->Socket->Queue($Data);

        $this->SourceQuery->GetRules();
    }

    /**
     * @return string[][]
     */
    public function BadPacketProvider(): array
    {
        return
        [
            [ "" ],
            [ "\xff\xff\xff\xff" ], // No type
            [ "\xff\xff\xff\xff\x49" ], // Correct type, but no data after
            [ "\xff\xff\xff\xff\x6D" ], // Old info packet, but tests are done for source
            [ "\xff\xff\xff\xff\x11" ], // Wrong type
            [ "\x11\x11\x11\x11" ], // Wrong header
            [ "\xff" ], // Should be 4 bytes, but it's 1
        ];
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testGetChallengeTwice(): void
    {
        $this->Socket->Queue("\xFF\xFF\xFF\xFF\x41\x11\x11\x11\x11");
        $this->Socket->Queue("\xFF\xFF\xFF\xFF\x45\x01\x00ayy\x00lmao\x00");
        self::assertEquals([ 'ayy' => 'lmao' ], $this->SourceQuery->GetRules());

        $this->Socket->Queue("\xFF\xFF\xFF\xFF\x45\x01\x00wow\x00much\x00");
        self::assertEquals([ 'wow' => 'much' ], $this->SourceQuery->GetRules());
    }

    /**
     * @param array $RawInput
     * @param array $ExpectedOutput
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider RulesProvider
     */
    public function testGetRules(array $RawInput, array $ExpectedOutput): void
    {
        $this->Socket->Queue(hex2bin("ffffffff4104fce20e")); // Challenge

        foreach ($RawInput as $Packet) {
            $this->Socket->Queue(hex2bin($Packet));
        }

        $RealOutput = $this->SourceQuery->GetRules();

        self::assertEquals($ExpectedOutput, $RealOutput);
    }

    /**
     * @return array
     *
     * @throws JsonException
     */
    public function RulesProvider(): array
    {
        $DataProvider = [];

        $Files = glob(__DIR__ . '/Rules/*.raw', GLOB_ERR);

        foreach ($Files as $File) {
            $DataProvider[] =
            [
                file($File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES),
                json_decode(
                    file_get_contents(
                        str_replace('.raw', '.json', $File)
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            ];
        }

        return $DataProvider;
    }

    /**
     * @param string[] $RawInput
     * @param array $ExpectedOutput
     *
     * @throws InvalidPacketException
     * @throws SocketException
     *
     * @dataProvider PlayersProvider
     */
    public function testGetPlayers(array $RawInput, array $ExpectedOutput): void
    {
        $this->Socket->Queue(hex2bin("ffffffff4104fce20e")); // Challenge

        foreach ($RawInput as $Packet) {
            $this->Socket->Queue(hex2bin($Packet));
        }

        $RealOutput = $this->SourceQuery->GetPlayers();

        self::assertEquals($ExpectedOutput, $RealOutput);
    }

    /**
     * @return array
     *
     * @throws JsonException
     */
    public function PlayersProvider(): array
    {
        $DataProvider = [];

        $Files = glob(__DIR__ . '/Players/*.raw', GLOB_ERR);

        foreach ($Files as $File) {
            $DataProvider[] =
            [
                file($File, FILE_SKIP_EMPTY_LINES | FILE_IGNORE_NEW_LINES),
                json_decode(
                    file_get_contents(
                        str_replace('.raw', '.json', $File)
                    ),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                )
            ];
        }

        return $DataProvider;
    }

    /**
     * @throws InvalidPacketException
     * @throws SocketException
     */
    public function testPing(): void
    {
        $this->Socket->Queue("\xFF\xFF\xFF\xFF\x6A\x00");
        self::assertTrue($this->SourceQuery->Ping());

        $this->Socket->Queue("\xFF\xFF\xFF\xFF\xEE");
        self::assertFalse($this->SourceQuery->Ping());
    }
}
