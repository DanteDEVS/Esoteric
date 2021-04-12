<?php

namespace ethaniccc\Esoteric;

use pocketmine\utils\TextFormat;

final class Settings {

	public const SETBACK_INSTANT = "instant";
	public const SETBACK_SMOOTH = "smooth";
	private $data;
	private $setbackType;

	public function __construct(array $configData) {
		$this->data = $configData;
	}

	public function getCheckSettings(string $type, string $subType): ?array {
		if (!isset($this->data["detections"]))
			return null;
		if (!isset($this->data["detections"][$type]))
			return null;
		return ($this->data["detections"][$type])[$subType] ?? null;
	}

	public function getPrefix(): string {
		return ($this->data["prefix"] ?? "§l§7[§c!§7]") . TextFormat::RESET;
	}

	public function getAlertCooldown(): float {
		return $this->data["alert_cooldown"] ?? 5.0;
	}

	public function getAlertMessage(): string {
		return $this->data["alert_message"];
	}

	public function getSetbackType(): string {
		if ($this->setbackType === null) {
			$this->setbackType = isset($this->data["setback_type"]) ? (in_array($this->data["setback_type"], [self::SETBACK_INSTANT, self::SETBACK_SMOOTH]) ? $this->data["setback_type"] : "none") : self::SETBACK_SMOOTH;
		}
		return $this->setbackType;
	}

	public function getKickMessage(): string {
		return $this->data["kick_message"] ?? "{prefix} Kicked (code={code}) | Contact staff if this issue persists";
	}

	public function getBanMessage(): string {
		return $this->data["ban_message"] ?? "{prefix} Banned (code={code}) | Make a ticket if this is a mistake";
	}

	public function getWebhookLink(): ?string {
		return isset($this->data["discord_webhook"]) ? ($this->data["discord_webhook"] !== 'none' ? $this->data["discord_webhook"] : null) : null;
	}
}