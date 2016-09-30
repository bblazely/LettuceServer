CREATE DEFINER=`root`@`localhost` TRIGGER `entity_schema_public_id-TrigBU-AssignPublicIdCount` BEFORE UPDATE ON `entity_schema_public_id` FOR EACH ROW BEGIN
	IF NEW.public_id <> OLD.public_id THEN
		SET NEW.public_id_count = (SELECT IFNULL(MAX(public_id_count), -1) + 1 FROM entity_schema_public_id WHERE public_id=NEW.public_id);
	END IF;
END