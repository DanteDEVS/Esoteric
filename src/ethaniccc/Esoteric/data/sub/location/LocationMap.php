<?php

namespace ethaniccc\Esoteric\data\sub\location;

use ethaniccc\Esoteric\data\PlayerData;
use ethaniccc\Esoteric\data\process\NetworkStackLatencyHandler;
use ethaniccc\Esoteric\utils\EvictingList;
use ethaniccc\Esoteric\utils\PacketUtils;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\Server;
use pocketmine\world\Position;
use function count;
use function is_null;

/**
 * Class LocationMap
 * @package ethaniccc\Esoteric\data\sub
 * LocationMap is a class that stores estimated client side locations in an array. This will be used in some combat checks.
 */
final class LocationMap {

	/** @var LocationData[] - Estimated client-sided locations */
	public array $locations = [];
	/** @var BatchPacket - A batch packet that contains entity locations along with a NetworkStackLatencyPacket */
	public BatchPacket $needSend;
	/** @var Position[] */
	public array $needSendArray = [];

	public function __construct() {
		$this->compressor->compress(PacketBatch::fromPackets(LevelChunkPacket::withoutCache($this->chunkX, $this->chunkZ, $subCount, $payload))->getBuffer())
		$this->needSend = new BatchPacket();
		$this->needSend->setCompressionLevel(0);
	}

	/**
	 * @param MoveActorAbsolutePacket|MovePlayerPacket $packet
	 */
	function addPacket(MovePlayerPacket|MoveActorAbsolutePacket $packet): void {
		$locationData = $this->locations[$packet->entityRuntimeId] ?? null;
		if (is_null($locationData)) return;
		if (($packet instanceof MovePlayerPacket && $packet->mode !== MovePlayerPacket::MODE_NORMAL) || ($packet instanceof MoveActorAbsolutePacket && $packet->flags >= 2)) {
			$data = $this->locations[$packet->entityRuntimeId] ?? null;
			if ($data !== null) {
				$data->isSynced = 0;
				$data->newPosRotationIncrements = 1;
			}
		}
		$this->needSend->addPacket($packet);
		$this->needSendArray[$packet->entityRuntimeId] = $packet->position->subtract(0, $locationData->locationOffset);
	}

	function addEntity(Entity $entity, Vector3 $startPos): void {
		$locationData = new LocationData();
		$locationData->entityRuntimeId = $entity->getId();
		$locationData->newPosRotationIncrements = 0;
		$locationData->currentLocation = clone $startPos;
		$locationData->lastLocation = clone $startPos;
		$locationData->receivedLocation = clone $startPos;
		$locationData->history = new EvictingList(3);
		$locationData->isHuman = $entity instanceof Human;
		$locationData->locationOffset = $entity->getOffsetPosition($entity->getPosition())->y - $entity->getPosition()->y;
		$this->locations[$entity->getId()] = $locationData;
	}

	function removeEntity(int $entityRuntimeId): void {
		unset($this->locations[$entityRuntimeId]);
	}

	function send(PlayerData $data): void {
		if (count($this->needSendArray) === 0 || !$data->loggedIn) {
			return;
		}
		$networkStackLatencyHandler = NetworkStackLatencyHandler::getInstance();
		$pk = $networkStackLatencyHandler->next($data);
		$batch = clone $this->needSend;
		$batch->addPacket($pk);
		$batch->encode();
		$locations = $this->needSendArray;
		$this->needSend = new BatchPacket();
		$this->needSend->setCompressionLevel(0);
		$this->needSendArray = [];
		$timestamp = $pk->timestamp;
		PacketUtils::sendPacketSilent($data, $batch, true, function () use ($data, $timestamp): void {
			$data->tickProcessor->waiting[$timestamp] = $data->currentTick;
		});
		$networkStackLatencyHandler->forceHandle($data, $pk->timestamp, function () use ($locations): void {
			foreach ($locations as $entityRuntimeId => $location) {
				if (isset($this->locations[$entityRuntimeId])) {
					$locationData = $this->locations[$entityRuntimeId];
					$locationData->newPosRotationIncrements = 3;
					$locationData->receivedLocation = $location;
				}
			}
		});
	}

	function executeTick(): void {
		foreach ($this->locations as $entityRuntimeId => $locationData) {
			if (($entity = Server::getInstance()->findEntity($entityRuntimeId)) === null) {
				// entity go brrt !
				unset($this->locations[$entityRuntimeId]);
				unset($this->needSendArray[$entityRuntimeId]);
			} else {
				if ($locationData->newPosRotationIncrements > 0) {
					$locationData->lastLocation = clone $locationData->currentLocation;
					$locationData->currentLocation->x = ($locationData->currentLocation->x + (($locationData->receivedLocation->x - $locationData->currentLocation->x) / $locationData->newPosRotationIncrements));
					$locationData->currentLocation->y = ($locationData->currentLocation->y + (($locationData->receivedLocation->y - $locationData->currentLocation->y) / $locationData->newPosRotationIncrements));
					$locationData->currentLocation->z = ($locationData->currentLocation->z + (($locationData->receivedLocation->z - $locationData->currentLocation->z) / $locationData->newPosRotationIncrements));
				} elseif ($locationData->newPosRotationIncrements === 0) {
					// don't need to clone all the time... lol
					$locationData->lastLocation = clone $locationData->currentLocation;
				}
				$bb = $entity->getBoundingBox();
				$locationData->hitboxWidth = ($bb->maxX - $bb->minX) * 0.5;
				$locationData->hitboxHeight = $bb->maxY - $bb->minY;
				$locationData->history->add($locationData->lastLocation);
				$locationData->newPosRotationIncrements--;
				$locationData->isSynced++;
			}
		}
	}

	function get(int $entityRuntimeId): ?LocationData {
		return $this->locations[$entityRuntimeId] ?? null;
	}

}