<?php

namespace ethaniccc\Esoteric\listener;

use ethaniccc\Esoteric\Esoteric;
use ethaniccc\Esoteric\network\RaklibOverride;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\StartGamePacket;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\PlayerMovementSettings;
use pocketmine\network\mcpe\protocol\types\PlayerMovementType;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\query\DedicatedQueryNetworkInterface;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

class EsotericEventListener implements Listener{

	protected bool $networkRegistered = false;

	/**
	 * @param PlayerPreLoginEvent $event
	 *
	 * @priority LOWEST
	 */
	public function log(PlayerPreLoginEvent $event) : void{
		foreach(Server::getInstance()->getNameBans()->getEntries() as $entry){
			if($entry->getSource() === "Esoteric AC" && $entry->getName() === strtolower($event->getPlayerInfo()->getUsername())){
				$event->setKickReason(PlayerPreLoginEvent::KICK_REASON_PLUGIN, $entry->getReason());
				break;
			}
		}
	}

	public function login(PlayerLoginEvent $event) : void{
		$data = Esoteric::getInstance()->dataManager->get($event->getPlayer()->getNetworkSession());
		$data->player = $event->getPlayer();
	}

	/**
	 * @param PlayerQuitEvent $event
	 *
	 * @priority LOWEST
	 */
	public function quit(PlayerQuitEvent $event) : void{
		$data = Esoteric::getInstance()->dataManager->get($event->getPlayer()->getNetworkSession());
		$message = null;
		foreach($data->checks as $check){
			$checkData = $check->getData();
			if($checkData["violations"] >= 1){
				if($message === null){
					$message = "";
				}
				$message .= TextFormat::YELLOW . $checkData["full_name"] . TextFormat::WHITE . " - " . $checkData["description"] . TextFormat::GRAY . " (" . TextFormat::RED . "x" . var_export(round($checkData["violations"], 3), true) . TextFormat::GRAY . ")" . PHP_EOL;
			}
		}
		Esoteric::getInstance()->logCache[strtolower($event->getPlayer()->getName())] = $message === null ? TextFormat::GREEN . "This player has no logs" : $message;
		Esoteric::getInstance()->dataManager->remove($event->getPlayer()->getNetworkSession());
	}

	public function receive(DataPacketReceiveEvent $event) : void{
		$packet = $event->getPacket();
		$session = $event->getOrigin();
		$playerData = Esoteric::getInstance()->dataManager->get($session) ?? Esoteric::getInstance()->dataManager->add($session);
		if($playerData->isDataClosed){
			return;
		}
		$playerData->inboundProcessor->execute($packet, $playerData);
		if($playerData->playerOS === DeviceOS::PLAYSTATION){
			return;
		}
		foreach($playerData->checks as $check){
			if($check->enabled()){
				$check->getTimings()->startTiming();
				$check->inbound($packet, $playerData);
				$check->getTimings()->stopTiming();
			}
		}
		if($packet instanceof PlayerAuthInputPacket){
			$event->cancel(); // prevent debug from spamming console
		}
	}

	public function send(DataPacketSendEvent $event) : void{
		foreach($event->getTargets() as $target){
			$playerData = Esoteric::getInstance()->dataManager->get($target);
			if($playerData === null){
				continue;
			}
			foreach($event->getPackets() as $packet){
				if($packet instanceof StartGamePacket){
					$packet->playerMovementSettings = new PlayerMovementSettings(PlayerMovementType::SERVER_AUTHORITATIVE_V2_REWIND, 20, false);
				}
				$playerData->outboundProcessor->execute($packet, $playerData);
			}
		}
	}

	public function onNetworkRegister(NetworkInterfaceRegisterEvent $event) : void{
		$interface = $event->getInterface();
		if($interface instanceof RakLibInterface && !$interface instanceof RaklibOverride){
			$event->cancel();
			if(!$this->networkRegistered){
				Server::getInstance()->getNetwork()->registerInterface(new RaklibOverride(Server::getInstance()));
				$this->networkRegistered = true;
			}
		}elseif($interface instanceof DedicatedQueryNetworkInterface){
			$event->cancel();
		}
	}
}
