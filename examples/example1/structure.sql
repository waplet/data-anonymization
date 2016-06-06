CREATE TABLE `jaundzimusie` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `reg_place` VARCHAR(100) NULL DEFAULT NULL,
  `first_name` VARCHAR(100) NULL DEFAULT NULL,
  `other_names` VARCHAR(100) NULL DEFAULT NULL,
  `birth_date` DATE NULL DEFAULT NULL,
  `sex` SET('Vīrietis','Sieviete') NULL DEFAULT NULL,
  `district_id` INT(11) NULL DEFAULT NULL,
  `active_date` DATE NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
)
COLLATE='utf8_general_ci'
ENGINE=InnoDB
AUTO_INCREMENT=1;

