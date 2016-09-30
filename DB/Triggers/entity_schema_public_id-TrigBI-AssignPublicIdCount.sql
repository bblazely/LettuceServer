CREATE DEFINER=`root`@`localhost` TRIGGER `entity_schema_public_id-TrigBI-AssignPublicIdCount` BEFORE INSERT ON `entity_schema_public_id` FOR EACH ROW BEGIN
	SET NEW.public_id_count = (SELECT IFNULL(MAX(public_id_count), -1) + 1 FROM entity_schema_public_id WHERE public_id=NEW.public_id);
END