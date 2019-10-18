<?php namespace Heroes\Convert;
/*
 * heroes-convert
 *
 * Convert HeroesDataParser to heroes-talents, for Heroes of the Storm
 * https://github.com/tattersoftware/heroes-convert
 * 
 */

require_once 'Base.php';

/**
 * Class Connect
 *
 * Connects talents to their abilities.
 */
class Connect extends Base
{
	/**
	 * Ability key to use for the link
	 *
	 * @var string
	 */
	public $linkKey = 'abilityId';
	
	/**
	 * Update each talent's abilityLinks to heroes-talent format
	 *
	 * @return $this
	 */
	public function run()
	{
		$abilities = $this->abilitiesByNameId();
		$this->updateAbilityLinks($abilities);
		
		return $this;
	}

	/**
	 * Get every ability indexed by nameId
	 *
	 * @return array
	 */
	protected function abilitiesByNameId(): array
	{
		$return = [];
		
		// Traverse heroes for each ability
		foreach ($this->heroes as $shortname => $hero)
		{
			foreach ($hero['abilities'] as $ability)
			{
				$return[$ability['nameId']] = $ability;
			}
		}
		
		return $return;
	}

	/**
	 * Traverse heroes for each talent and update its abilityLinks
	 *
	 * @param array  Array of indexed abilities
	 */
	protected function updateAbilityLinks(array $abilities)
	{
		// Traverse heroes for each talent
		foreach ($this->heroes as $shortname => $hero)
		{
			foreach ($hero['talents'] as $level => $talents)
			{
				foreach ($talents as $i => $talent)
				{
					$abilityId = in_array($talent['type'], ['Active', 'Passive']) ? $hero['hyperlinkId'] . '|' . $talent['type'] : null;
					$links = [];
					
					if (! empty($talent['abilityLinks']))
					{
						foreach ($talent['abilityLinks'] as $nameId)
						{
							if (! isset($abilities[$nameId]))
							{
								$this->logMessage("Unable to match ability nameId '{$nameId}' for {$talent['talentTreeId']}", 'info');

								continue;
							}
						
							$abilityId = $abilityId ?? $abilities[$nameId]['abilityId'] ?? null;
							$links[]   = $abilities[$nameId][$this->linkKey];
						}
					
						if (empty($links))
						{
							$this->logMessage("No abilities matched for {$talent['talentTreeId']}. Searched for: " . implode(', ', $talent['abilityLinks']), 'warning');
						}
							
						// Overwrite with the new links
						$this->heroes[$shortname]['talents'][$level][$i]['abilityLinks'] = $links;
					}
					
					// Set the abilityId
					$this->heroes[$shortname]['talents'][$level][$i]['abilityId'] = $abilityId;
				}
			}
		}
	}
}
