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
 * Class Locale
 *
 * Add locale-specific names and descriptions to parsed hero data
 */
class Locale extends Base
{
	/**
	 * Array of unit strings from HDP file.
	 *
	 * @var array
	 */
	protected $heroStrings;

	/**
	 * Array of ability/talent strings from HDP file.
	 *
	 * @var array
	 */
	protected $abiltalentStrings;
	
	/**
	 * Load data from the the game strings file.
	 *
	 * @param array     $heroes           Parsed hero array
	 * @param string    $gamestringsPath  Path to a valid gamestrings file
	 */
	public function __construct(array $heroes, string $gamestringsPath)
	{
		parent::__construct($heroes);
		
		$this->loadStrings($gamestringsPath);
	}

	/**
	 * Verify a file path and returns the parsed JSON contents as an array
	 *
	 * @param string    $path Path to a JSON file
	 */
	protected function loadStrings(string $path)
	{
		// Verify the file
		if (! is_file($path))
		{
			throw new \RuntimeException('Unable	to locate file: ' . $path);
		}
		
		// Load raw contents
		$data = file_get_contents($path);
		if (empty($data))
		{
			throw new \RuntimeException('Unable to read data from file: ' . $path);
		}
		
		// Decode JSON data
		$array = json_decode($data, true);
		unset($data);
		if ($array === false)
		{
			throw new \RuntimeException('Error #' . json_last_error() . ' parsing ' . $path . ': ' . json_last_error_msg());
		}
		
		// Verify format
		if (! isset($array['meta']['version']))
		{
			throw new \RuntimeException('Invalid gameStrings file: ' . $path);
		}

		$this->heroStrings       = $array['gamestrings']['unit'];
		$this->abiltalentStrings = $array['gamestrings']['abiltalent'];

		$this->logMessage('Gamestrings loaded: version ' . $array['meta']['version'] . ', locale ' . $array['meta']['locale']);

		unset($array);
	}

	/**
	 * Update the heroes array with HDP gamestrings
	 *
	 * @return $this
	 */
	public function run()
	{
		$strings = [];
		
		$this->addHeroStrings();
		$this->addAbiltalentStrings();
		
		return $this;
	}

	/**
	 * Add relevant strings to each hero
	 *
	 * @return array
	 */
	protected function addHeroStrings()
	{
		// Get descriptions
		//$strings['description']  = $this->heroDescriptions($this->heroStrings['description']);
				
		// Traverse heroes and set matching strings
		foreach ($this->heroes as $shortname => $hero)
		{
			foreach (['name', 'type', 'role', 'expandedRole', 'description'] as $key)
			{
				if (isset($this->heroStrings[strtolower($key)][$hero['cHeroId']]))
				{
					$this->heroes[$shortname][$key] = $this->heroStrings[strtolower($key)][$hero['cHeroId']];
				}
			}
			
			if (isset($strings['description'][$shortname]))
			{
				$this->heroes[$shortname]['description'] = $strings['description'][$shortname];
			}
		}
		
		// Free up some memory
		unset($this->heroStrings);
	}

	/**
	 * Add descriptions to abilities and talents
	 *
	 * @return array
	 */
	protected function addAbiltalentStrings()
	{
		// Get each set of strings
		$strings['name']        = $this->abiltalentNames($this->abiltalentStrings['name']);
		$strings['description'] = $this->abiltalentDescriptions($this->abiltalentStrings['full']);
		$strings['cooldown']    = $this->abiltalentCooldowns($this->abiltalentStrings['cooldown']);
		$strings['manaCost']    = $this->abiltalentManaCosts($this->abiltalentStrings['energy']);
		
		// Free up some memory
		unset($this->abiltalentStrings);
		
		// Traverse heroes and set matching strings
		foreach ($this->heroes as $shortname => $hero)
		{
			// Check each ability
			foreach ($hero['abilities'] as $i => $ability)
			{
				foreach (['name', 'description', 'cooldown', 'manaCost'] as $key)
				{
					if (isset($strings[$key][$ability['uid']]))
					{
						$this->heroes[$shortname]['abilities'][$i][$key] = $strings[$key][$ability['uid']];
					}
				}
			}
			
			// Check each talent
			foreach ($hero['talents'] as $level => $talents)
			{
				foreach ($talents as $i => $talent)
				{
					foreach (['name', 'description', 'cooldown', 'manaCost'] as $key)
					{
						if (isset($strings[$key][$talent['uid']]))
						{
							$this->heroes[$shortname]['talents'][$level][$i][$key] = $strings[$key][$talent['uid']];
						}
					}
				}
			}
		}

		unset($strings);
	}

	/**
	 * Fetch ability names by their UID
	 *
	 * @param array   $cooldowns  HDP name gamestrings
	 *
	 * @return array
	 */
	protected function abiltalentNames(array $names): array
	{
		$return = [];
		
		foreach ($names as $id => $name)
		{
			// Hash the UID
			$uid = $this->abiltalentUid($id);
			
			// Standardize single quotes and trim
			$return[$uid] = trim(str_replace("\u{2019}", "'", $name));
		}
		
		return $return;		
	}

	/**
	 * Fetch ability & talent descriptions by their UID
	 *
	 * Descriptions contain hypertext, e.g.:
	 *  Alarak targets an area and channels for <c val=\"bfd4fd\">1</c> second,
	 *  becoming Protected and Unstoppable. After, if he took damage from an enemy
	 *  Hero, he sends a shockwave that deals <c val=\"bfd4fd\">275~~0.04~~</c> damage.
	 *
	 * @param array   $descriptions  HDP abiltalent description gamestrings
	 *
	 * @return array
	 */
	protected function abiltalentDescriptions(array $descriptions): array
	{
		$return = [];

		foreach ($descriptions as $id => $description)
		{
			// Hash the UID
			$uid = $this->abiltalentUid($id);
			
			// Expand scaling values, e.g. "~~0.04~~" => "(+4% per level)"
			$description = preg_replace_callback('#~~0\.0\d+~~#',
				function ($matches) {
					$num = (float)trim($matches[0], '~') * 100;
					return " (+{$num}% per level)";
				},
				$description
			);

			// Newline tags become spaces
			$description = str_replace('<n/>', ' ', $description);

			// Remove tags
			$description = preg_replace('#<.+?>#', '', $description);
			
			// Standardize single quotes
			$description = str_replace("\u{2019}", "'", $description);

			$return[$uid] = trim(str_replace('   ', '  ', $description));
		}
		
		return $return;		
	}

	/**
	 * Fetch ability and talent cooldowns by their UID
	 *
	 * @param array   $cooldowns  HDP cooldown gamestrings
	 *
	 * @return array
	 */
	protected function abiltalentCooldowns(array $cooldowns): array
	{
		$return = [];
		
		foreach ($cooldowns as $id => $cooldown)
		{
			// Hash the UID
			$uid = $this->abiltalentUid($id);
			
			// Remove tags
			$cooldown = preg_replace('#<.+?>#', '', $cooldown);
			
			// Strip everything but the number of seconds
			$cooldown = filter_var($cooldown, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

			$return[$uid] = $cooldown;
		}
		
		return $return;		
	}

	/**
	 * Fetch ability and talent mana costs by their UID
	 *
	 * @param array   $costs  HDP energy gamestrings
	 *
	 * @return array
	 */
	protected function abiltalentManaCosts(array $costs): array
	{
		$return = [];
		
		foreach ($costs as $id => $cost)
		{
			// Hash the UID
			$uid = $this->abiltalentUid($id);
			
			// Find the colon before the actual cost
			$pos = strrpos($cost, ':');
			
			// Lop everything before the number and trim the final "</s>"
			$cost = substr($cost, $pos + 2, -4);
					
			$return[$uid] = $cost;
		}
		
		return $return;		
	}
}
