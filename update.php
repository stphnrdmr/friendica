<?php

use Friendica\Core\Addon;
use Friendica\Core\Config;
use Friendica\Core\L10n;
use Friendica\Core\PConfig;
use Friendica\Core\Worker;
use Friendica\Database\DBA;
use Friendica\Model\Contact;
use Friendica\Model\Item;
use Friendica\Model\User;
use Friendica\Util\DateTimeFormat;

require_once 'include/dba.php';

/**
 *
 * update.php - automatic system update
 *
 * This function is responsible for doing post update changes to the data
 * (not the structure) in the database.
 *
 * Database structure changes are done in src/Database/DBStructure.php
 *
 * If there is a need for a post process to a structure change, update this file
 * by adding a new function at the end with the number of the new DB_UPDATE_VERSION.
 *
 * The numbered script in this file has to be exactly like the DB_UPDATE_VERSION
 *
 * Example:
 * You are currently on version 4711 and you are preparing changes that demand an update script.
 *
 * 1. Create a function "update_4712()" here in the update.php
 * 2. Apply the needed structural changes in src/Database/DBStructure.php
 * 3. Set DB_UPDATE_VERSION in boot.php to 4712.
 *
 * If you need to run a script before the database update, name the function "pre_update_4712()"
 */

function update_1178() {
	require_once 'mod/profiles.php';

	$profiles = q("SELECT `uid`, `about`, `locality`, `pub_keywords`, `gender` FROM `profile` WHERE `is-default`");

	foreach ($profiles AS $profile) {
		if ($profile["about"].$profile["locality"].$profile["pub_keywords"].$profile["gender"] == "")
			continue;

		$profile["pub_keywords"] = profile_clean_keywords($profile["pub_keywords"]);

		$r = q("UPDATE `contact` SET `about` = '%s', `location` = '%s', `keywords` = '%s', `gender` = '%s' WHERE `self` AND `uid` = %d",
				DBA::escape($profile["about"]),
				DBA::escape($profile["locality"]),
				DBA::escape($profile["pub_keywords"]),
				DBA::escape($profile["gender"]),
				intval($profile["uid"])
			);
	}
}

function update_1179() {
	if (Config::get('system','no_community_page'))
		Config::set('system','community_page_style', CP_NO_COMMUNITY_PAGE);

	// Update the central item storage with uid=0
	Worker::add(PRIORITY_LOW, "threadupdate");

	return UPDATE_SUCCESS;
}

function update_1181() {

	// Fill the new fields in the term table.
	Worker::add(PRIORITY_LOW, "TagUpdate");

	return UPDATE_SUCCESS;
}

function update_1189() {

	if (strlen(Config::get('system','directory_submit_url')) &&
		!strlen(Config::get('system','directory'))) {
		Config::set('system','directory', dirname(Config::get('system','directory_submit_url')));
		Config::delete('system','directory_submit_url');
	}

	return UPDATE_SUCCESS;
}

function update_1191() {
	Config::set('system', 'maintenance', 1);

	if (Addon::isEnabled('forumlist')) {
		$addon = 'forumlist';
		$addons = Config::get('system', 'addon');
		$addons_arr = [];

		if ($addons) {
			$addons_arr = explode(",",str_replace(" ", "", $addons));

			$idx = array_search($addon, $addons_arr);
			if ($idx !== false){
				unset($addons_arr[$idx]);
				//delete forumlist manually from addon and hook table
				// since Addon::uninstall() don't work here
				q("DELETE FROM `addon` WHERE `name` = 'forumlist' ");
				q("DELETE FROM `hook` WHERE `file` = 'addon/forumlist/forumlist.php' ");
				Config::set('system','addon', implode(", ", $addons_arr));
			}
		}
	}

	// select old formlist addon entries
	$r = q("SELECT `uid`, `cat`, `k`, `v` FROM `pconfig` WHERE `cat` = '%s' ",
		DBA::escape('forumlist')
	);

	// convert old forumlist addon entries in new config entries
	if (DBA::isResult($r)) {
		foreach ($r as $rr) {
			$uid = $rr['uid'];
			$family = $rr['cat'];
			$key = $rr['k'];
			$value = $rr['v'];

			if ($key === 'randomise')
				PConfig::delete($uid,$family,$key);

			if ($key === 'show_on_profile') {
				if ($value)
					PConfig::set($uid,feature,forumlist_profile,$value);

				PConfig::delete($uid,$family,$key);
			}

			if ($key === 'show_on_network') {
				if ($value)
					PConfig::set($uid,feature,forumlist_widget,$value);

				PConfig::delete($uid,$family,$key);
			}
		}
	}

	Config::set('system', 'maintenance', 0);

	return UPDATE_SUCCESS;
}

function update_1203() {
	$r = q("UPDATE `user` SET `account-type` = %d WHERE `page-flags` IN (%d, %d)",
		DBA::escape(Contact::ACCOUNT_TYPE_COMMUNITY), DBA::escape(Contact::PAGE_COMMUNITY), DBA::escape(Contact::PAGE_PRVGROUP));
}

function update_1244() {
	// Sets legacy_password for all legacy hashes
	DBA::update('user', ['legacy_password' => true], ['SUBSTR(password, 1, 4) != "$2y$"']);

	// All legacy hashes are re-hashed using the new secure hashing function
	$stmt = DBA::select('user', ['uid', 'password'], ['legacy_password' => true]);
	while($user = DBA::fetch($stmt)) {
		DBA::update('user', ['password' => User::hashPassword($user['password'])], ['uid' => $user['uid']]);
	}

	// Logged in users are forcibly logged out
	DBA::delete('session', ['1 = 1']);

	return UPDATE_SUCCESS;
}

function update_1245() {
	$rino = Config::get('system', 'rino_encrypt');

	if (!$rino) {
		return UPDATE_SUCCESS;
	}

	Config::set('system', 'rino_encrypt', 1);

	return UPDATE_SUCCESS;
}

function update_1247() {
	// Removing hooks with the old name
	DBA::e("DELETE FROM `hook`
WHERE `hook` LIKE 'plugin_%'");

	// Make sure we install the new renamed ones
	Addon::reload();
}

function update_1260() {
	Config::set('system', 'maintenance', 1);
	Config::set('system', 'maintenance_reason', L10n::t('%s: Updating author-id and owner-id in item and thread table. ', DateTimeFormat::utcNow().' '.date('e')));

	$items = DBA::p("SELECT `id`, `owner-link`, `owner-name`, `owner-avatar`, `network` FROM `item`
		WHERE `owner-id` = 0 AND `owner-link` != ''");
	while ($item = DBA::fetch($items)) {
		$contact = ['url' => $item['owner-link'], 'name' => $item['owner-name'],
			'photo' => $item['owner-avatar'], 'network' => $item['network']];
		$cid = Contact::getIdForURL($item['owner-link'], 0, false, $contact);
		if (empty($cid)) {
			continue;
		}
		Item::update(['owner-id' => $cid], ['id' => $item['id']]);
	}
	DBA::close($items);

	DBA::e("UPDATE `thread` INNER JOIN `item` ON `thread`.`iid` = `item`.`id`
		SET `thread`.`owner-id` = `item`.`owner-id` WHERE `thread`.`owner-id` = 0");

	$items = DBA::p("SELECT `id`, `author-link`, `author-name`, `author-avatar`, `network` FROM `item`
		WHERE `author-id` = 0 AND `author-link` != ''");
	while ($item = DBA::fetch($items)) {
		$contact = ['url' => $item['author-link'], 'name' => $item['author-name'],
			'photo' => $item['author-avatar'], 'network' => $item['network']];
		$cid = Contact::getIdForURL($item['author-link'], 0, false, $contact);
		if (empty($cid)) {
			continue;
		}
		Item::update(['author-id' => $cid], ['id' => $item['id']]);
	}
	DBA::close($items);

	DBA::e("UPDATE `thread` INNER JOIN `item` ON `thread`.`iid` = `item`.`id`
		SET `thread`.`author-id` = `item`.`author-id` WHERE `thread`.`author-id` = 0");

	Config::set('system', 'maintenance', 0);
	return UPDATE_SUCCESS;
}

function update_1261() {
	// This fixes the results of an issue in the develop branch of 2018-05.
	DBA::update('contact', ['blocked' => false, 'pending' => false], ['uid' => 0, 'blocked' => true, 'pending' => true]);
	return UPDATE_SUCCESS;
}

function update_1278() {
	Config::set('system', 'maintenance', 1);
	Config::set('system', 'maintenance_reason', L10n::t('%s: Updating post-type.', DateTimeFormat::utcNow().' '.date('e')));

	Item::update(['post-type' => Item::PT_PAGE], ['bookmark' => true]);
	Item::update(['post-type' => Item::PT_PERSONAL_NOTE], ['type' => 'note']);

	Config::set('system', 'maintenance', 0);

	return UPDATE_SUCCESS;
}

function update_1278() {
	Config::set('system', 'maintenance', 1);
	Config::set('system', 'maintenance_reason', L10n::t('%s: Updating post-type.', DBM::date().' '.date('e')));

	Item::update(['post-type' => Item::PT_PAGE], ['bookmark' => true]);
	Item::update(['post-type' => Item::PT_PERSONAL_NOTE], ['type' => 'note']);

	Config::set('system', 'maintenance', 0);

	return UPDATE_SUCCESS;
}
