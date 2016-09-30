-- --------------------------------------------------------
-- Host:                         127.0.0.1
-- Server version:               5.5.5-10.1.0-MariaDB-1~trusty-log - mariadb.org binary distribution
-- Server OS:                    debian-linux-gnu
-- HeidiSQL Version:             8.3.0.4694
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

-- Dumping structure for table lettuce.entity
DROP TABLE IF EXISTS `entity`;
CREATE TABLE IF NOT EXISTS `entity` (
  `entity_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT 'Entity PK',
  `entity_type` enum('person','org') NOT NULL,
  `date_created` int(11) unsigned NOT NULL,
  `public_id_count` int(11) unsigned NOT NULL COMMENT 'Public ID Uniqueness Counter',
  `public_id` varchar(255) NOT NULL COMMENT 'Entity Public ID',
  PRIMARY KEY (`entity_id`),
  UNIQUE KEY `u.public_id.public_id_count` (`public_id`,`public_id_count`),
  UNIQUE KEY `u.entity_id.entity_type` (`entity_id`,`entity_type`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=latin1;

-- Dumping structure for table lettuce.entity_role_assignment
DROP TABLE IF EXISTS `entity_role_assignment`;
CREATE TABLE IF NOT EXISTS `entity_role_assignment` (
  `entity_id` bigint(20) unsigned NOT NULL COMMENT 'Role assigned to this Entity',
  `target_entity_id` bigint(20) unsigned NOT NULL COMMENT 'Grants access to this entity',
  `role_id` bigint(20) unsigned NOT NULL COMMENT 'Role ID',
  KEY `fk.era.role_id-r.role_id` (`role_id`),
  KEY `fk.era.target_entity_id-e.entity_id` (`target_entity_id`),
  KEY `fk.era.entity_id-e.entity_id` (`entity_id`),
  CONSTRAINT `fk.era.entity_id-e.entity_id` FOREIGN KEY (`entity_id`) REFERENCES `entity` (`entity_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk.era.role_id-r.role_id` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk.era.target_entity_id-e.entity_id` FOREIGN KEY (`target_entity_id`) REFERENCES `entity` (`entity_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1;

-- Dumping structure for table lettuce.login_from_native
DROP TABLE IF EXISTS `login_from_native`;
CREATE TABLE IF NOT EXISTS `login_from_native` (
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Farnarkel User ID',
  `verified` tinyint(4) NOT NULL DEFAULT '0',
  `email` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
  `password_hash` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `password_hash_invert_case` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  `password_hash_first_upper` varchar(255) CHARACTER SET utf8 COLLATE utf8_bin NOT NULL,
  PRIMARY KEY (`email`),
  KEY `fk.lfn.user_id-u.user_id` (`user_id`),
  CONSTRAINT `fk.lfn.user_id-u.user_id` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci ROW_FORMAT=COMPACT;

-- Dumping structure for table lettuce.login_from_socialnetwork
DROP TABLE IF EXISTS `login_from_socialnetwork`;
CREATE TABLE IF NOT EXISTS `login_from_socialnetwork` (
  `user_id` bigint(20) unsigned NOT NULL,
  `socialnetwork_id` int(11) NOT NULL,
  `socialnetwork_user_id` varchar(128) NOT NULL COMMENT 'ID assigned to this user by the social auth provider',
  `email` varchar(100) DEFAULT NULL,
  `avatar_img_url` varchar(1024) DEFAULT NULL,
  PRIMARY KEY (`socialnetwork_id`,`socialnetwork_user_id`),
  KEY `fk.lfsp.entity_id-u.user_id` (`user_id`),
  CONSTRAINT `fk.lfsp.entity_id-u.user_id` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk.lfsp.socialnetwork_id-sp.socialnetwork_id` FOREIGN KEY (`socialnetwork_id`) REFERENCES `socialnetworks` (`socialnetwork_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.messages
DROP TABLE IF EXISTS `messages`;
CREATE TABLE IF NOT EXISTS `messages` (
  `message_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `channel_id` int(11) DEFAULT NULL,
  `send_date` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `template_id` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`message_id`),
  KEY `message_sender_user` (`send_date`),
  KEY `sys_id_index` (`message_id`,`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.message_ack
DROP TABLE IF EXISTS `message_ack`;
CREATE TABLE IF NOT EXISTS `message_ack` (
  `user_id` bigint(20) unsigned NOT NULL,
  `channel_id` int(11) NOT NULL DEFAULT '0',
  `last_viewed_message_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`channel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.message_from_org
DROP TABLE IF EXISTS `message_from_org`;
CREATE TABLE IF NOT EXISTS `message_from_org` (
  `message_id` bigint(20) unsigned NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`message_id`,`org_id`),
  KEY `fk.mfr.role_id` (`org_id`),
  KEY `fk.mfr.user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.message_from_user
DROP TABLE IF EXISTS `message_from_user`;
CREATE TABLE IF NOT EXISTS `message_from_user` (
  `message_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`message_id`,`user_id`),
  KEY `i.search.user_id` (`user_id`),
  CONSTRAINT `fk.mfu.message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.message_meta_data
DROP TABLE IF EXISTS `message_meta_data`;
CREATE TABLE IF NOT EXISTS `message_meta_data` (
  `message_id` bigint(20) unsigned NOT NULL,
  `meta_key` varchar(64) NOT NULL,
  `meta_data` text NOT NULL,
  `verbosity` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`message_id`,`meta_key`),
  CONSTRAINT `fk.m.message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.message_needs_permission
DROP TABLE IF EXISTS `message_needs_permission`;
CREATE TABLE IF NOT EXISTS `message_needs_permission` (
  `message_id` bigint(20) unsigned NOT NULL,
  `permission_id` int(11) unsigned NOT NULL,
  PRIMARY KEY (`message_id`,`permission_id`),
  KEY `fk.mnr.permission_id` (`permission_id`),
  CONSTRAINT `fk.mnr.message_id` FOREIGN KEY (`message_id`) REFERENCES `message_to_org` (`message_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.message_to_org
DROP TABLE IF EXISTS `message_to_org`;
CREATE TABLE IF NOT EXISTS `message_to_org` (
  `message_id` bigint(20) unsigned NOT NULL,
  `org_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`message_id`,`org_id`),
  KEY `fk.mto.org_id` (`org_id`),
  CONSTRAINT `fk.mto.message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping data for table lettuce.message_to_org: ~0 rows (approximately)
/*!40000 ALTER TABLE `message_to_org` DISABLE KEYS */;
/*!40000 ALTER TABLE `message_to_org` ENABLE KEYS */;


-- Dumping structure for table lettuce.message_to_user
DROP TABLE IF EXISTS `message_to_user`;
CREATE TABLE IF NOT EXISTS `message_to_user` (
  `message_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `read` bit(1) DEFAULT b'0',
  `deleted` bit(1) NOT NULL DEFAULT b'0',
  PRIMARY KEY (`message_id`,`user_id`,`deleted`),
  KEY `fk.mtu.user_id` (`user_id`),
  CONSTRAINT `fk.mtu.message_id` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping structure for table lettuce.organisation
DROP TABLE IF EXISTS `organisation`;
CREATE TABLE IF NOT EXISTS `organisation` (
  `org_id` bigint(20) unsigned NOT NULL,
  `name` varchar(128) NOT NULL,
  PRIMARY KEY (`org_id`),
  KEY `i.name` (`name`),
  CONSTRAINT `fk.o.org_id-e.entity_id` FOREIGN KEY (`org_id`) REFERENCES `entity` (`entity_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping data for table lettuce.organisation: ~0 rows (approximately)
/*!40000 ALTER TABLE `organisation` DISABLE KEYS */;
/*!40000 ALTER TABLE `organisation` ENABLE KEYS */;


-- Dumping structure for table lettuce.permission
DROP TABLE IF EXISTS `permission`;
CREATE TABLE IF NOT EXISTS `permission` (
  `permission_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mask` int(10) unsigned NOT NULL,
  `entity_type` enum('person','org','common') NOT NULL,
  `name` varchar(255) NOT NULL,
  `name_constant` varchar(64) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `MaskForEntityType` (`mask`,`entity_type`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8;

-- Dumping data for table lettuce.permission: ~2 rows (approximately)
/*!40000 ALTER TABLE `permission` DISABLE KEYS */;
INSERT INTO `permission` (`permission_id`, `mask`, `entity_type`, `name`, `name_constant`, `description`) VALUES
  (1, 1, 'org', 'Approve Member', 'MEMBER_APPROVE', 'Approve Membership Application'),
  (2, 2, 'org', 'Remove Member', 'MEMBER_REMOVE', 'Remove Member from Org'),
  (3, 4, 'org', 'Delete Organisation', 'ORG_DELETE', 'Delete Organisation'),
  (4, 8, 'org', 'Edit Organisation News', 'ORG_NEWS_EDIT', 'Edit Org News Feed'),
  (5, 16, 'org', 'Member', 'ORG_VIEW', 'Is a member of this Org'),
  (6, 4294967295, 'common', 'Owner', 'OWNER\r\n', 'Owner of the Entity'),
  (7, 1, 'common', 'View', 'VIEW', 'Can view the profile of the Entity');
/*!40000 ALTER TABLE `permission` ENABLE KEYS */;


-- Dumping structure for table lettuce.role
DROP TABLE IF EXISTS `role`;
CREATE TABLE IF NOT EXISTS `role` (
  `role_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8;

-- Dumping data for table lettuce.role: ~1 rows (approximately)
/*!40000 ALTER TABLE `role` DISABLE KEYS */;
INSERT INTO `role` (`role_id`, `name`) VALUES
  (1, 'Organisation Owner');
/*!40000 ALTER TABLE `role` ENABLE KEYS */;


-- Dumping structure for table lettuce.role_has_permission
DROP TABLE IF EXISTS `role_has_permission`;
CREATE TABLE IF NOT EXISTS `role_has_permission` (
  `role_id` bigint(20) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`),
  KEY `fk.rhr.permission_id` (`permission_id`),
  CONSTRAINT `fk.rhp.permisson_id-p.permission_id` FOREIGN KEY (`permission_id`) REFERENCES `permission` (`permission_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk.rhp.role_id-r.role_id` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Dumping data for table lettuce.role_has_permission: ~1 rows (approximately)
/*!40000 ALTER TABLE `role_has_permission` DISABLE KEYS */;
INSERT INTO `role_has_permission` (`role_id`, `permission_id`) VALUES
  (1, 6);
/*!40000 ALTER TABLE `role_has_permission` ENABLE KEYS */;


-- Dumping structure for table lettuce.socialnetworks
DROP TABLE IF EXISTS `socialnetworks`;
CREATE TABLE IF NOT EXISTS `socialnetworks` (
  `socialnetwork_id` int(11) NOT NULL DEFAULT '0',
  `socialnetwork_id_string` char(16) DEFAULT NULL,
  `display_name` varchar(64) NOT NULL COMMENT 'Common English Name',
  `popup_width` int(11) NOT NULL DEFAULT '500' COMMENT 'Initial width of auth pop up window.',
  `popup_height` int(11) NOT NULL DEFAULT '600' COMMENT 'Initial height of auth popup window',
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `key_id` varchar(128) NOT NULL,
  `key_secret` varchar(128) DEFAULT NULL,
  PRIMARY KEY (`socialnetwork_id`,`enabled`),
  UNIQUE KEY `u.socialnetwork_id_string-enabled` (`socialnetwork_id_string`,`enabled`),
  KEY `i.enabled` (`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='Valid OAuth Provider List';

-- Dumping structure for table lettuce.user
DROP TABLE IF EXISTS `user`;
CREATE TABLE IF NOT EXISTS `user` (
  `user_id` bigint(20) unsigned NOT NULL COMMENT 'Entity ID for User Resources',
  `email` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
  `first_name` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `last_name` varchar(64) COLLATE utf8_unicode_ci DEFAULT NULL,
  `display_name` varchar(128) COLLATE utf8_unicode_ci DEFAULT NULL,
  `avatar_img_url` varchar(1024) COLLATE utf8_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `u_email` (`email`),
  CONSTRAINT `fk.u.user_id-entity.entity_id` FOREIGN KEY (`user_id`) REFERENCES `entity` (`entity_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;
/*!40000 ALTER TABLE `user` ENABLE KEYS */;

/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IF(@OLD_FOREIGN_KEY_CHECKS IS NULL, 1, @OLD_FOREIGN_KEY_CHECKS) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
