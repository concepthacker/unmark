<?php defined('BASEPATH') OR exit("No direct script access allowed");

class Migration_Batshit_Crazy extends CI_Migration {

    public function up()
    {

      set_time_limit(0);
      // Make sure all tables are INNODB, UTF-8
      // Original migration they were not
      // If anyone download that version and ran successfully, some keys may not be created correctly
      $this->db->query("ALTER TABLE `groups` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `groups_invites` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `marks` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `migrations` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `users` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `users_groups` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `users_marks` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");
      $this->db->query("ALTER TABLE `users_smartlabels` ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci");

       /*
      - Start updates for labels table
      - Import default system labels
      - Import any user smart labels
      */

      // Create new labels table
      $this->db->query("
        CREATE TABLE IF NOT EXISTS `labels` (
          `label_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented key.',
          `smart_label_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'If a smart label, the label_id to use if a match is found.',
          `user_id` bigint(20) UNSIGNED DEFAULT NULL COMMENT 'If a label but owned by a user, place the users.user_id here.',
          `name` varchar(50) DEFAULT NULL COMMENT 'The name of the label.',
          `domain` varchar(255) DEFAULT NULL COMMENT 'The hostname of the domain to match. Keep in all lowercase.',
          `path` varchar(100) DEFAULT NULL COMMENT 'The path to find to for smartlabels to match. If null, just match host.',
          `smart_key` varchar(32) DEFAULT NULL COMMENT 'MD5 checksum of domain and path for lookup purposes.',
          `active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 is active, 0 if not. Defaults to 1.',
          `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
          `last_updated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'The last datetime this record was updated.',
          PRIMARY KEY (`label_id`),
          CONSTRAINT `FK_label_smart_label_id` FOREIGN KEY (`smart_label_id`) REFERENCES `labels` (`label_id`)   ON UPDATE CASCADE ON DELETE CASCADE,
          CONSTRAINT `FK_label_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)   ON UPDATE CASCADE ON DELETE CASCADE,
          INDEX `smart_label_id`(smart_label_id),
          INDEX `user_id`(user_id),
          INDEX `smart_key`(smart_key)
        ) ENGINE=`InnoDB` DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;
      ");

      // Add default data to labels
      // Default: Unlabeled, Read, Watch, Listen, Buy, Eat & Drink, Do
      // Then add all the smart labels
      $default_labels = array(
        'unlabeled' => array('label_id' => 1, 'name' => 'Unlabeled'),
        'read'      => array('label_id' => 2, 'name' => 'Read'),
        'watch'     => array('label_id' => 3, 'name' => 'Watch'),
        'listen'    => array('label_id' => 4, 'name' => 'Listen'),
        'buy'       => array('label_id' => 5, 'name' => 'Buy'),
        'eatdrink'  => array('label_id' => 6, 'name' => 'Eat & Drink'),
        'do'        => array('label_id' => 7, 'name' => 'Do')
      );

      foreach ($default_labels as $label) {
        $this->db->query("INSERT INTO `labels` (`name`, `created_on`) VALUES ('" . $label['name'] . "', '" . date('Y-m-d H:i:s') . "')");
      }

      // Start default system smart labels
      $smart_labels = array(
        // read
        '2' => array(
          'domains' => array('php.net', 'api.rubyonrails.org', 'ruby-doc.org', 'docs.jquery.com', 'codeigniter.com', 'css-tricks.com', 'developer.apple.com'),
          'paths'   => array('/manual', '', '', '', '/user_guide', '/almanac', '/library')
        ),
        // watch
        '3' => array(
          'domains' => array('youtube.com', 'viddler.com', 'devour.com', 'ted.com', 'vimeo.com'),
          'paths'   => array('/watch', '/v', '/video', '/talks', '')
        ),
        //buy
        '5' => array(
          'domains' => array('svpply.com', 'amazon.com', 'fab.com', 'zappos.com'),
          'paths'   => array('/item', '/gp/product', '/sale', '')
        ),
        // eat & drink
        '6' => array(
          'domains' => array('simplyrecipes.com', 'allrecipes.com', 'epicurious.com', 'foodnetwork.com', 'food.com'),
          'paths'   => array('/recipes', '', '/recipes', '/recipes', '/recipe')
        )
      );

      // Loop thru smart labels and add them up
      foreach ($smart_labels as $label_id => $arr) {
        foreach ($arr as $k => $arr1) {
          foreach ($arr['domains'] as $key => $val) {
            $domain   = $arr['domains'][$key];
            $path     = (empty($arr['paths'][$key])) ? '' : "'" . $arr['paths'][$key] . "', ";
            $path_c   = (empty($arr['paths'][$key])) ? '' : "`path`, ";
            $md5      = md5($domain . $path);
            $this->db->query("
              INSERT INTO `labels`
              (`smart_label_id`, `domain`, " . $path_c . "`smart_key`, `created_on`)
              VALUES ('" . $label_id . "', '" . $domain . "', " . $path . "'" . $md5 . "', '" . date('Y-m-d H:i:s') . "')
            ");
          }
        }
      }

      // Now open users_smartlabels, import into labels
      $labels = $this->db->query("SELECT userid, domain, path, label FROM users_smartlabels");
      if ($labels->num_rows() >= 1) {
        foreach ($labels->result() as $label) {

          // Proceed only if domain is not empty
          $current_label = strtolower($label->label);
          if (! empty($label->domain) && ! empty($label->userid) && is_numeric($label->userid) && ! empty($current_label) && array_key_exists($current_label, $default_labels)) {
            // Clean up host (remove www.)
            // Make sure it's lowercase
            $domain   = str_replace('www.', '', strtolower($label->domain));
            $label_id = $default_labels[$current_label]['label_id'];
            $path     = (empty($label->path)) ? '' : "'" . $label->path . "', ";
            $path_c   = (empty($label->path)) ? '' : "`path`, ";
            $md5      = md5($domain . $path);

            // Find an occurences of this record
            $q  = $this->db->query("
              SELECT COUNT(*) FROM labels
              WHERE user_id = '" . $label->userid . "'
              AND domain = '" . $domain . "'
              AND smart_label_id = '" . $label_id . "'
            ");

            $row   = $q->row();
            $total = (integer) $row->{'COUNT(*)'};

            // If not found, add it
            if ($total < 1) {
              $res = $this->db->query("
                INSERT INTO `labels`
                (`smart_label_id`, `user_id`, `domain`, " . $path_c . "`smart_key`, `created_on`)
                VALUES ('" . $label_id . "', '" . $label->userid . "', '" . $domain . "', " . $path . "'" . $md5 . "', '" . date('Y-m-d H:i:s') . "')
              ");
            }

          }
        }
      }

       /*
      - End labels import
      */


      /*
      - Start updates for marks table
      */

      // Move all recipes to oembed column
      // oembed will be renamed embed for all embeddable content
      $marks = $this->db->query("SELECT id, recipe FROM `marks` WHERE recipe != '' AND LOWER(recipe) != 'none' AND recipe IS NOT NULL");
      if ($marks->num_rows() >= 1) {
        foreach ($marks->result() as $mark) {
          $res = $this->db->query("UPDATE `marks` SET `oembed` = '" . addslashes($mark->recipe) . "' WHERE `id` = '" . $mark->id . "'");
        }
      }

      // Update marks table
      $this->db->query("ALTER TABLE `marks` DROP COLUMN `recipe`");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `id` `mark_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented id for marks.'");
      $this->db->query("ALTER TABLE `marks` DROP PRIMARY KEY, ADD PRIMARY KEY (`mark_id`)");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `title` `title` varchar(150) NOT NULL COMMENT 'The title from the page being bookmarked.'");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `url` `url` text NOT NULL COMMENT 'The full url from the page being bookmarked.'");
      $this->db->query("ALTER TABLE `marks` ADD COLUMN `url_key` varchar(32) DEFAULT NULL COMMENT 'The MD5 checksum of the url for lookup purposes.' AFTER `url`");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `oembed` `embed` text DEFAULT NULL COMMENT 'The embedded content that could appear on the mark\'s info page.'");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `dateadded` `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'The datetime this record was created.'");
      $this->db->query("ALTER TABLE `marks` ADD COLUMN `last_updated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'The last datetime this record was updated.' AFTER `created_on`");

      // Get all urls from marks table, create MD5 checksum, update record
      $marks = $this->db->query("SELECT mark_id, url FROM `marks`");

      // If any exists, move on
      if ($marks->num_rows() >= 1) {

        // Set up empty arrays to hold marks that may get deleted or changed
        $deleted = array();
        $changed = array();

        // Loop thru results
        foreach ($marks->result() as $mark) {

          // If no url, remove it from DB and add to deleted list
          if (empty($mark->url)) {
            $res = $this->db->query("DELETE FROM `marks` WHERE `mark_id` = '" . $mark->mark_id . "'");
            array_push($deleted, $mark->mark_id);
          }
          // Else, create checksum from url
          // See if any records already exist with this checksum
          // If so, get the current mark id and the one from DB that has matching checksum
          // Remove the current mark
          // Add to changed array where key is the current mark's id and the value is the record's mark id that was foun
          // If no matches, simply add the checksum for the current mark
          else {
            $checksum = md5($mark->url);
            $record = $this->db->query("SELECT mark_id FROM `marks` WHERE `url_key` = '" . $checksum . "' LIMIT 1");
            if ($record->num_rows() == 1) {
              $record = $record->row();
              $changed[$mark->mark_id] = $record->mark_id;
              $res = $this->db->query("DELETE FROM `marks` WHERE `mark_id` = '" . $mark->mark_id . "'");
            }
            else {
              $res = $this->db->query("UPDATE `marks` SET `url_key` = '" . $checksum . "' WHERE `mark_id` = '" . $mark->mark_id . "'");
            }
          }
        }

        // If the deleted array is not empty
        // Remove any user marks that are attached to it
        if (! empty($deleted)) {
          foreach ($deleted as $mark_id) {
            $res = $this->db->query("DELETE FROM `users_marks` WHERE `urlid` = '" . $mark_id . "'");
          }
        }

        // If the changed array is not empty
        // Update any user marks from the old mark id to the new one
        if (! empty($changed)) {
          foreach ($changed as $old_id => $new_id) {
            $res = $this->db->query("UPDATE `users_marks` SET `urlid` = '" . $new_id . "' WHERE `urlid` = '" . $old_id . "'");
          }
        }
      }

      // Finally, add a unique index for url key
      $this->db->query("ALTER TABLE `marks` ADD UNIQUE `url_key`(url_key)");


      /*
      - End updates for marks table
      */


      // Update groups table
      $this->db->query("ALTER TABLE `groups` DROP COLUMN `urlname`");
      $this->db->query("ALTER TABLE `groups` DROP COLUMN `uid`");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `id` `group_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented primary key for groups.'");
      $this->db->query("ALTER TABLE `groups` DROP PRIMARY KEY, ADD PRIMARY KEY (`group_id`)");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `createdby` `user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The user id that coorelates to an account in users.user_id'");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `name` `name` varchar(50) NOT NULL COMMENT 'The group name.'");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `description` `description` varchar(255) DEFAULT NULL COMMENT 'The optional description for this group.'");
      $this->db->query("ALTER TABLE `groups` ADD COLUMN `active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 if group is active, 0 if not. Defaults to 1.' AFTER `description`");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `datecreated` `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'The datetime this record was created.' AFTER `active`");
      $this->db->query("ALTER TABLE `groups` ADD COLUMN `last_updated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'The last datetime this record was updated.' AFTER `created_on`");
      $this->db->query("ALTER TABLE `groups` ADD INDEX `user_id`(user_id)");
      $this->db->query("ALTER TABLE `groups` ADD CONSTRAINT `FK_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)   ON UPDATE CASCADE ON DELETE CASCADE");

      // Update group_invties table
      $this->db->query("RENAME TABLE `groups_invites` TO `group_invites`");
      $this->db->query("ALTER TABLE `group_invites` DROP COLUMN `invitedby`");
      $this->db->query("ALTER TABLE `group_invites` DROP COLUMN `status`");
      $this->db->query("ALTER TABLE `group_invites` CHANGE COLUMN `id` `group_invite_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented key.'");
      $this->db->query("ALTER TABLE `group_invites` DROP PRIMARY KEY, ADD PRIMARY KEY (`group_invite_id`)");
      $this->db->query("ALTER TABLE `group_invites` CHANGE COLUMN `groupid` `group_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The group_id it from groups.group_id'");
      $this->db->query("ALTER TABLE `group_invites` CHANGE COLUMN `emailaddress` `email` varchar(150) NOT NULL COMMENT 'The email address of the invited user.'");
      $this->db->query("ALTER TABLE `group_invites` ADD COLUMN `accepted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = yes, 0 =no' AFTER `email`");
      $this->db->query("ALTER TABLE `group_invites` CHANGE COLUMN `dateinvited` `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'The datetime the record was created' AFTER `accepted`");
      $this->db->query("ALTER TABLE `group_invites` ADD COLUMN `last_updated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'The datetime the record was last updated.' AFTER `created_on`");
      $this->db->query("ALTER TABLE `group_invites` ADD INDEX `group_id`(group_id)");
      $this->db->query("ALTER TABLE `group_invites` ADD CONSTRAINT `FK_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`)   ON UPDATE CASCADE ON DELETE CASCADE");


      // Create marks_to_groups table
      // FKs will be added later after the data has been moved
      $this->db->query("
        CREATE TABLE IF NOT EXISTS `user_marks_to_groups` (
          `user_marks_to_group_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented key.',
          `user_mark_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The mark_id from users_to_marks.users_to_mark_id',
          `group_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The group_id from groups.group_id',
          PRIMARY KEY (`user_marks_to_group_id`),
          INDEX `user_mark_id`(user_mark_id),
          INDEX `group_id`(group_id)
        ) ENGINE=`InnoDB` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;
      ");


      // Update users table
      // We are moving to active = 1 or 0 column, before altering table, switch active = 1, inactive = 0
      $res = $this->db->query("UPDATE `users` SET status = '0' WHERE status <> 'active'");
      $res = $this->db->query("UPDATE `users` SET status = '1' WHERE status = 'active'");
      $this->db->query("ALTER TABLE `users` DROP COLUMN `salt`");
      $this->db->query("ALTER TABLE `users` CHANGE COLUMN `status` `active` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = active, 0 = inactive'");

      // Remove the users_smartlabels table
      $this->db->query("DROP TABLE IF EXISTS `users_smartlabels`");

      // Update users_groups table
      $this->db->query("RENAME TABLE `users_groups` TO `users_to_groups`");
      $this->db->query("ALTER TABLE `users_to_groups` DROP COLUMN `status`");
      $this->db->query("ALTER TABLE `users_to_groups` CHANGE COLUMN `id` `users_to_group_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented key.'");
      $this->db->query("ALTER TABLE `users_to_groups` DROP PRIMARY KEY, ADD PRIMARY KEY (`users_to_group_id`)");
      $this->db->query("ALTER TABLE `users_to_groups` CHANGE COLUMN `groupid` `group_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The group id from groups.group_id'");
      $this->db->query("ALTER TABLE `users_to_groups` CHANGE COLUMN `userid` `user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The user_id from users.user_id'");
      $this->db->query("ALTER TABLE `users_to_groups` ADD COLUMN `active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = active, 0 = not active' AFTER `user_id`");
      $this->db->query("ALTER TABLE `users_to_groups` CHANGE COLUMN `datejoined` `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'The datetime the user has joined this group.'");
      $this->db->query("ALTER TABLE `users_to_groups` ADD COLUMN `last_updated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'The last datetime this record was updated.' AFTER `created_on`");
      $this->db->query("ALTER TABLE `users_to_groups` ADD INDEX `group_id`(group_id)");
      $this->db->query("ALTER TABLE `users_to_groups` ADD INDEX `user_id`(user_id)");
      $this->db->query("ALTER TABLE `users_to_groups` ADD CONSTRAINT `FK_utg_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`)   ON UPDATE CASCADE ON DELETE CASCADE");
      $this->db->query("ALTER TABLE `users_to_groups` ADD CONSTRAINT `FK_utg_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)   ON UPDATE CASCADE ON DELETE CASCADE");

      /*
      - Start users_to_marks transistion
      */

      // Update `tags` field to a single numeric in order to translate to a FK for labels
      // Move group id to user_marks_to_groups table

      $marks = $this->db->query("SELECT id, urlid, tags, groups, status FROM `users_marks`");
      $archived = array();
      $delete   = array();
      if ($marks->num_rows() >= 1) {

        // Loop thru results
        foreach ($marks->result() as $mark) {
          $tags      = explode(',', $mark->tags);
          $label_id  = 1;
          $group_id  = $mark->groups;
          $mark_id   = $mark->id;

          // Figure if we should delete this mark
          $res = $this->db->query("SELECT mark_id FROM marks WHERE mark_id = '" . $mark->urlid . "' LIMIT 1");
          if ($res->num_rows() < 1) {
            array_push($delete, $mark_id);
          }

          // If it was archived, save here to update later
          if (strtolower($mark->status) == 'archive') {
            array_push($archived, $mark_id);
          }

          foreach ($tags as $tag) {
            $tag = trim(strtolower($tag));
            if (array_key_exists($tag, $default_labels)) {
              $label_id = $default_labels[$tag]['label_id'];
              break;
            }
          }

          // Update tags
          $res = $this->db->query("UPDATE `users_marks` SET tags = '" . $label_id . "' WHERE id = '" . $mark_id . "'");

          // Update groups to user_marks_to_groups table
          if (! empty($group_id) && is_numeric($group_id)) {

            // Check if this mark is in marks_to_groups table already
            $q     = $this->db->query("SELECT COUNT(*) FROM `user_marks_to_groups` WHERE user_mark_id = '" . $mark_id . "' AND group_id = '" . $group_id . "'");
            $row   = $q->row();
            $total = (integer) $row->{'COUNT(*)'};

            if ($total < 1) {
              $res = $this->db->query("INSERT INTO `user_marks_to_groups` (`user_mark_id`, `group_id`) VALUES ('" . $mark_id . "', '" . $group_id . "')");
            }
          }
        }
      }

      // Remove any urlids in users_marks that do NOT exist in marks table
      // If we skip this step the FK creation will fail
      // Data is a bit dirty because when marks were deleted, it never deleted their children, leaving little orphan annies
      // Fix it!
      if (! empty($delete)) {
        foreach ($delete as $id) {
          $res = $this->db->query("DELETE FROM `users_marks` WHERE id = '" . $id . "'");
        }
      }


      // Update table name & structure
      $this->db->query("RENAME TABLE `users_marks` TO `users_to_marks`");
      $this->db->query("ALTER TABLE `users_to_marks` DROP COLUMN `addedby`");
      $this->db->query("ALTER TABLE `users_to_marks` DROP COLUMN `groups`");
      $this->db->query("ALTER TABLE `users_to_marks` DROP COLUMN `status`");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `id` `users_to_mark_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'The auto-incremented key.'");
      $this->db->query("ALTER TABLE `users_to_marks` DROP PRIMARY KEY, ADD PRIMARY KEY (`users_to_mark_id`)");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `urlid` `mark_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The mark_id from marks.mark_id.' AFTER `users_to_mark_id`");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `userid` `user_id` bigint(20) UNSIGNED NOT NULL COMMENT 'The user_id from users.user_id' AFTER `mark_id`");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `tags` `label_id` bigint(20) UNSIGNED NOT NULL DEFAULT '1' COMMENT 'The label_id from labels.label_id.'");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `note` `notes` text DEFAULT NULL");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `dateadded` `created_on` datetime NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT 'The datetime this record was created.'");
      $this->db->query("ALTER TABLE `users_to_marks` CHANGE COLUMN `datearchived` `archived_on` datetime DEFAULT NULL COMMENT 'The datetime this mark was archived. NULL = not archived.'");
      $this->db->query("ALTER TABLE `users_to_marks` ADD COLUMN `last_updated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'The last datetime this record was updated.' AFTER `archived_on`");
      $this->db->query("ALTER TABLE `users_to_marks` ADD INDEX `mark_id`(mark_id)");
      $this->db->query("ALTER TABLE `users_to_marks` ADD INDEX `user_id`(user_id)");
      $this->db->query("ALTER TABLE `users_to_marks` ADD INDEX `label_id`(label_id)");
      $this->db->query("ALTER TABLE `users_to_marks` ADD CONSTRAINT `FK_utm_mark_id` FOREIGN KEY (`mark_id`) REFERENCES `marks` (`mark_id`)   ON UPDATE CASCADE ON DELETE CASCADE");
      $this->db->query("ALTER TABLE `users_to_marks` ADD CONSTRAINT `FK_utm_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)   ON UPDATE CASCADE ON DELETE CASCADE");
      $this->db->query("ALTER TABLE `users_to_marks` ADD CONSTRAINT `FK_utm_label_id` FOREIGN KEY (`label_id`) REFERENCES `labels` (`label_id`)   ON UPDATE CASCADE ON DELETE CASCADE");

      // Fix archived marks
      // First set all date_archived fields to NULL properly
      $res = $this->db->query("UPDATE `users_to_marks` SET archived_on = NULL WHERE archived_on IS NOT NULL");
      if (! empty($archived)) {
        foreach ($archived as $users_to_mark_id) {
          $res = $this->db->query("UPDATE `users_to_marks` SET archived_on = '" . date('Y-m-d H:i:s') . "' WHERE users_to_mark_id = '" . $users_to_mark_id . "'");
        }
      }

      // Now add FKs to marks_to_groups table
      $this->db->query("ALTER TABLE `user_marks_to_groups` ADD CONSTRAINT `FK_umtg_user_mark_id` FOREIGN KEY (`user_mark_id`) REFERENCES `users_to_marks` (`users_to_mark_id`)   ON UPDATE CASCADE ON DELETE CASCADE");
      $this->db->query("ALTER TABLE `user_marks_to_groups` ADD CONSTRAINT `FK_umtg_group_id` FOREIGN KEY (`group_id`) REFERENCES `groups` (`group_id`)   ON UPDATE CASCADE ON DELETE CASCADE");

      /*
      - End users_to_marks transistion
      */
    }

    public function down()
    {
      set_time_limit(0);

      // Set default label/tags
      $default_labels = array('unlabeled', 'read', 'watch', 'listen', 'buy', 'eatdrink', 'do');

      // Drop FKs from user_marks_to_groups table
      // Don't want them to interfere with reverting to the users_marks table with no indexes
      $this->db->query("ALTER TABLE `user_marks_to_groups` DROP FOREIGN KEY `FK_umtg_user_mark_id`");
      $this->db->query("ALTER TABLE `user_marks_to_groups` DROP FOREIGN KEY `FK_umtg_group_id`");

      /*
      - Start users_marks transistion
      */

      // Find any archived marks and save id for later use
      $archived = array();
      $marks = $this->db->query("SELECT users_to_mark_id FROM `users_to_marks` WHERE archived_on IS NOT NULL");
      if ($marks->num_rows() >= 1) {

        // Loop thru results
        foreach ($marks->result() as $mark) {
          array_push($archived, $mark->users_to_mark_id);
        }
      }

      // Change all date_archived back to 0000-00-00 00:00:00 like they were originally
      $res = $this->db->query("UPDATE `users_to_marks` SET archived_on = '0000-00-00 00:00:00' WHERE archived_on IS NULL");

      // Revert users_marks table
      $this->db->query("RENAME TABLE `users_to_marks` TO `users_marks`");
      $this->db->query("ALTER TABLE `users_marks` DROP FOREIGN KEY `FK_utm_mark_id`");
      $this->db->query("ALTER TABLE `users_marks` DROP FOREIGN KEY `FK_utm_user_id`");
      $this->db->query("ALTER TABLE `users_marks` DROP FOREIGN KEY `FK_utm_label_id`");
      $this->db->query("ALTER TABLE `users_marks` DROP INDEX `mark_id`");
      $this->db->query("ALTER TABLE `users_marks` DROP INDEX `label_id`");
      $this->db->query("ALTER TABLE `users_marks` DROP INDEX `user_id`");
      $this->db->query("ALTER TABLE `users_marks` DROP COLUMN `last_updated`");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `users_to_mark_id` `id` int(11) NOT NULL AUTO_INCREMENT");
      $this->db->query("ALTER TABLE `users_marks` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `mark_id` `urlid` int(11) NOT NULL");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `user_id` `userid` int(11) NOT NULL AFTER `id`");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `label_id` `tags` text DEFAULT NULL");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `notes` `note` text DEFAULT NULL");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `created_on` `dateadded` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP");
      $this->db->query("ALTER TABLE `users_marks` CHANGE COLUMN `archived_on` `datearchived` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00'");
      $this->db->query("ALTER TABLE `users_marks` ADD COLUMN `status` varchar(255) DEFAULT NULL AFTER `dateadded`");
      $this->db->query("ALTER TABLE `users_marks` ADD COLUMN `addedby` int(11) NOT NULL AFTER `tags`");
      $this->db->query("ALTER TABLE `users_marks` ADD COLUMN `groups` int(11) NOT NULL AFTER `addedby`");


      // Revert archived marks
      $res = $this->db->query("UPDATE `users_marks` SET status = NULL WHERE status IS NOT NULL");
      if (! empty($archived)) {
        foreach ($archived as $id) {
          $res = $this->db->query("UPDATE `users_marks` SET status = 'archive' WHERE id = '" . $id . "'");
        }
      }

      // Add tags back
      $marks = $this->db->query("SELECT id, tags FROM `users_marks`");
      if ($marks->num_rows() >= 1) {

        // Loop thru results and update
        foreach ($marks->result() as $mark) {
          $label_id = $mark->tags - 1;
          $label    = (array_key_exists($label_id, $default_labels)) ? $default_labels[$label_id] : 'unlabeled';
          $res      = $this->db->query("UPDATE `users_marks` SET tags = '" . $label . "' WHERE id = '" . $mark->id . "'");
        }
      }

      // Add groups back to users_mark table
      $marks = $this->db->query("SELECT user_mark_id, group_id FROM `user_marks_to_groups`");
      if ($marks->num_rows() >= 1) {

        // Loop thru results and update
        foreach ($marks->result() as $mark) {
          $res = $this->db->query("UPDATE `users_marks` SET groups = '" . $mark->group_id . "' WHERE id = '" . $mark->user_mark_id . "'");
        }
      }

      /*
      - End users_marks transistion
      */


      // Drop marks_to_groups table
      $this->db->query("DROP TABLE IF EXISTS `user_marks_to_groups`");

      // Revert groups invite table
      $this->db->query("RENAME TABLE `group_invites` TO `groups_invites`");
      $this->db->query("ALTER TABLE `groups_invites` DROP FOREIGN KEY `FK_group_id`");
      $this->db->query("ALTER TABLE `groups_invites` DROP INDEX `group_id`");
      $this->db->query("ALTER TABLE `groups_invites` DROP COLUMN `accepted`");
      $this->db->query("ALTER TABLE `groups_invites` DROP COLUMN `last_updated`");
      $this->db->query("ALTER TABLE `groups_invites` CHANGE COLUMN `group_invite_id` `id` int(11) NOT NULL AUTO_INCREMENT");
      $this->db->query("ALTER TABLE `groups_invites` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)");
      $this->db->query("ALTER TABLE `groups_invites` CHANGE COLUMN `group_id` `groupid` int(11) NOT NULL");
      $this->db->query("ALTER TABLE `groups_invites` CHANGE COLUMN `email` `emailaddress` text NOT NULL");
      $this->db->query("ALTER TABLE `groups_invites` ADD COLUMN `invitedby` int(11) NOT NULL AFTER `emailaddress`");
      $this->db->query("ALTER TABLE `groups_invites` CHANGE COLUMN `created_on` `dateinvited` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
      $this->db->query("ALTER TABLE `groups_invites` ADD COLUMN `status` varchar(255) DEFAULT NULL AFTER `dateinvited`");

      // Revert users_to_groups table
      $this->db->query("RENAME TABLE `users_to_groups` TO `users_groups`");
      $this->db->query("ALTER TABLE `users_groups` DROP FOREIGN KEY `FK_utg_group_id`");
      $this->db->query("ALTER TABLE `users_groups` DROP FOREIGN KEY `FK_utg_user_id`");
      $this->db->query("ALTER TABLE `users_groups` DROP INDEX `group_id`");
      $this->db->query("ALTER TABLE `users_groups` DROP INDEX `user_id`");
      $this->db->query("ALTER TABLE `users_groups` DROP COLUMN `active`");
      $this->db->query("ALTER TABLE `users_groups` DROP COLUMN `last_updated`");
      $this->db->query("ALTER TABLE `users_groups` CHANGE COLUMN `users_to_group_id` `id` int(11) NOT NULL AUTO_INCREMENT");
      $this->db->query("ALTER TABLE `users_groups` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)");
      $this->db->query("ALTER TABLE `users_groups` CHANGE COLUMN `group_id` `groupid` int(11) NOT NULL");
      $this->db->query("ALTER TABLE `users_groups` CHANGE COLUMN `user_id` `userid` int(11) NOT NULL");
      $this->db->query("ALTER TABLE `users_groups` CHANGE COLUMN `created_on` `datejoined` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
      $this->db->query("ALTER TABLE `users_groups` ADD COLUMN `status` varchar(255) DEFAULT NULL AFTER `datejoined`");

      // Revert groups table
      $this->db->query("ALTER TABLE `groups` DROP FOREIGN KEY `FK_user_id`");
      $this->db->query("ALTER TABLE `groups` DROP INDEX `user_id`");
      $this->db->query("ALTER TABLE `groups` DROP COLUMN `last_updated`");
      $this->db->query("ALTER TABLE `groups` DROP COLUMN `active`");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `group_id` `id` int(11) NOT NULL AUTO_INCREMENT");
      $this->db->query("ALTER TABLE `groups` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `user_id` `createdby` int(11) NOT NULL");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `name` `name` varchar(255) NOT NULL");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `description` `description` text DEFAULT NULL ");
      $this->db->query("ALTER TABLE `groups` ADD COLUMN `urlname` varchar(255) DEFAULT NULL AFTER `description`");
      $this->db->query("ALTER TABLE `groups` ADD COLUMN `uid` text NOT NULL AFTER `urlname`");
      $this->db->query("ALTER TABLE `groups` CHANGE COLUMN `created_on` `datecreated` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP");

      // Revert marks table
      $this->db->query("ALTER TABLE `marks` DROP INDEX `url_key`");
      $this->db->query("ALTER TABLE `marks` DROP COLUMN `last_updated`");
      $this->db->query("ALTER TABLE `marks` DROP COLUMN `url_key`");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `mark_id` `id` int(11) NOT NULL AUTO_INCREMENT");
      $this->db->query("ALTER TABLE `marks` DROP PRIMARY KEY, ADD PRIMARY KEY (`id`)");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `title` `title` text NOT NULL");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `url` `url` text NOT NULL");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `embed` `oembed` text DEFAULT NULL");
      $this->db->query("ALTER TABLE `marks` CHANGE COLUMN `created_on` `dateadded` timestamp NOT NULL ON UPDATE CURRENT_TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
      $this->db->query("ALTER TABLE `marks` ADD COLUMN `recipe` text DEFAULT NULL AFTER `oembed`");

      // Move recipes back to the recipe column
      $marks = $this->db->query("SELECT id, oembed FROM `marks` WHERE oembed LIKE '%hrecipe%'");
      if ($marks->num_rows() >= 1) {
        foreach ($marks->result() as $mark) {
          $res = $this->db->query("UPDATE `marks` SET recipe = '" . addslashes($mark->oembed) . "', oembed = NULL WHERE `id` = '" . $mark->id . "'");
        }
      }

      // Revert user smart labels
      $this->db->query("
        CREATE TABLE IF NOT EXISTS `users_smartlabels` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `userid` int(11) NOT NULL,
          `domain` varchar(255) NOT NULL,
          `path` varchar(255) NOT NULL,
          `label` varchar(255) NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=`InnoDB` DEFAULT CHARACTER SET utf8 COLLATE utf8_unicode_ci;
      ");

      // Get all user smart labels from labels table and add back to users_smartlabels
      $labels = $this->db->query("SELECT smart_label_id, user_id, domain, path FROM `labels` WHERE smart_label_id IS NOT NULL AND user_id IS NOT NULL");
      if ($labels->num_rows() >= 1) {
        foreach ($labels->result() as $label) {

          // Proceed only if domain is not empty
          $label_id      = $label->smart_label_id - 1;
          $current_label = (isset($default_labels[$label_id])) ? $default_labels[$label_id] : null;
          if (! empty($current_label)) {
            $res = $this->db->query("
              INSERT INTO `users_smartlabels`
              (`userid`, `domain`, `path`, `label`)
              VALUES ('" . $label->user_id . "', '" . $label->domain . "', '" . $label->path . "', '" . $current_label . "')
            ");
          }
        }
      }

      // Drop labels table
      $this->db->query("DROP TABLE IF EXISTS `labels`");

      // Revert users table
      // Revert active to status, 0 = inactive, 1 = active
      $this->db->query("ALTER TABLE `users` CHANGE COLUMN `active` `status` varchar(25) NOT NULL DEFAULT 'inactive'");
      $res = $this->db->query("UPDATE `users` SET status = 'inactive' WHERE status <> '1'");
      $res = $this->db->query("UPDATE `users` SET status = 'active' WHERE status = '1'");
      $this->db->query("ALTER TABLE `users` ADD COLUMN `salt` varchar(50) DEFAULT NULL COMMENT 'The salt used to generate password.' AFTER `password`");

    }

}