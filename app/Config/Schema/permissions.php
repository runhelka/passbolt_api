<?php
/**
 * Permission Schema
 *
 * @copyright    copyright 2012 Passbolt.com
 * @license      http://www.passbolt.com/license
 * @package      app.Config.Schema.permission
 * @since        version 2.12.11
 */

class PermissionsSchema {

	public function init() {
		// Create the permissions functions
		$this->createFunctions();
		// Create the permissions views
		$this->createViews();
	}

	public static function getViewsSQL() {
		return array(
			"categories_parents" => "
				CREATE OR REPLACE ALGORITHM=UNDEFINED VIEW categories_parents AS
					SELECT c.id AS child_id, c.lft AS child_lft, c.rght AS child_rght, cp.id AS id, cp.lft AS lft, cp.rght AS rght
					FROM categories c, categories cp
					WHERE cp.lft <= c.lft 
						AND cp.rght >= c.rght;
			",
			"groups_categories_permissions" => "
				CREATE OR REPLACE ALGORITHM=UNDEFINED VIEW groups_categories_permissions AS
					SELECT 
						`g`.id AS group_id,
						`cp`.child_id AS category_id,
						`p`.id AS permission_id,
						`p`.type AS permission_type
					FROM permissions p
					LEFT JOIN `categories_parents` cp ON `cp`.id=`p`.aco_foreign_key
					INNER JOIN `groups` g ON `g`.id=`p`.aro_foreign_key
					WHERE `p`.aro = 'Group'
						AND `p`.aco = 'Category'
					ORDER BY `g`.id, `cp`.child_id, `cp`.lft DESC;
			",
			"groups_resources_permissions" => "
				CREATE OR REPLACE ALGORITHM=UNDEFINED VIEW groups_resources_permissions AS
					
					SELECT 
						`r`.id AS resource_id,
						`g`.id AS group_id,
						`p_direct`.id AS direct_permission_id,
						`p_inherited`.id AS inherited_permission_id,
						IFNULL(`p_direct`.id, `p_inherited`.id) AS permission_id,
						IFNULL(`p_direct`.type, `p_inherited`.type) AS permission_type
						/*IF(`p_direct`.id, '1', '0') AS inherited*/
					FROM (`resources` r JOIN `groups` g)
					LEFT JOIN `permissions` p_direct ON (
						`p_direct`.aro='Group'
						AND `p_direct`.aco='Resource'
						AND `p_direct`.aco_foreign_key = `r`.id
						AND `p_direct`.aro_foreign_key = `g`.id 
					)
					LEFT JOIN `permissions` p_inherited ON (
						p_inherited.id = (
							SELECT `gcp`.permission_id
							FROM `groups_categories_permissions` gcp,
								`categories_resources` cr
							WHERE `cr`.resource_id = `r`.id
							AND `gcp`.group_id = `g`.id
							AND `gcp`.category_id = `cr`.category_id
							ORDER BY `gcp`.permission_type DESC
							LIMIT 1
						)
					);
			",
			"users_categories_permissions" => "
				CREATE OR REPLACE ALGORITHM=UNDEFINED VIEW users_categories_permissions AS
					SELECT 
						`u`.id AS user_id,
						`c`.id AS category_id,
						`p_direct`.id AS direct_permission_id,
						`pg_inherited`.id AS inherited_permission_id,
						IFNULL(`p_direct`.id, IFNULL(`pu_inherited`.id, `pg_inherited`.id)) AS permission_id,
						IFNULL(`p_direct`.type, IFNULL(`pu_inherited`.type, `pg_inherited`.type)) AS permission_type
						
					FROM (`categories` c JOIN `users` u)
					
					/*  Get the direct permission for a given user */
					LEFT JOIN `permissions` p_direct ON (
						`p_direct`.aro='User'
						AND `p_direct`.aco='Category'
						AND `p_direct`.aco_foreign_key = `c`.id
						AND `p_direct`.aro_foreign_key = `u`.id 
					)
					
					/* Get inherited permissions functions of user's permissions applied to parent categories */
					LEFT JOIN `permissions` pu_inherited ON (
						pu_inherited.id = (
							SELECT `pu_pc`.id
							FROM `permissions` pu_pc, /* user's permissions applied to parent categories */
								`categories_parents` cp
							WHERE `pu_pc`.aro = 'User'
								AND `pu_pc`.aro_foreign_key = `u`.id
								AND `pu_pc`.aco_foreign_key = `cp`.id
								AND `cp`.child_id = `c`.id
							ORDER BY `cp`.lft DESC
							LIMIT 1
						)
					)
					
					/* Get inherited permissions functions of user's groups */
					LEFT JOIN `permissions` pg_inherited ON (
						pg_inherited.id = (
							SELECT `gcp`.permission_id
							FROM `groups_categories_permissions` gcp,
								`groups_users` gu
							WHERE `gcp`.category_id = `c`.id
							AND `gu`.user_id = `u`.id
							AND `gu`.group_id = `gcp`.group_id
							ORDER BY `gcp`.permission_type DESC
							LIMIT 1
						)
					);
			",
			"users_resources_permissions" => "
				CREATE OR REPLACE ALGORITHM=MERGE VIEW users_resources_permissions AS
				
					SELECT 
						`u`.id AS user_id,
						`r`.id AS resource_id,
						IFNULL(`p_direct`.id, IFNULL(`pu_inherited`.id, `pg_inherited`.id)) AS permission_id,
						IFNULL(`p_direct`.type, IFNULL(`pu_inherited`.type, `pg_inherited`.type)) AS permission_type
						/*IF(`p_direct`.id, '1', '0') AS inherited*/
					FROM (`resources` r JOIN `users` u)
					LEFT JOIN `permissions` p_direct ON (
						`p_direct`.aro='User'
						AND `p_direct`.aco='Resource'
						AND `p_direct`.aco_foreign_key = `r`.id
						AND `p_direct`.aro_foreign_key = `u`.id 
					)
					LEFT JOIN `permissions` pg_inherited ON (
						/* `p_direct`.id IS NULL */
						pg_inherited.id = (
							SELECT `grp`.permission_id
							FROM `groups_resources_permissions` grp,
								`groups_users` gu
							WHERE `grp`.resource_id = `r`.id
							AND `gu`.user_id = `u`.id
							AND `gu`.group_id = `grp`.group_id
							ORDER BY `grp`.permission_type DESC
							LIMIT 1
						)
					)
					
					LEFT JOIN `permissions` pu_inherited ON (
						/* `p_direct`.id IS NULL */
						pu_inherited.id = (
							SELECT `ucp`.permission_id
							FROM `users_categories_permissions` ucp,
								`categories_resources` cr
							WHERE `cr`.resource_id = `r`.id
							AND `ucp`.category_id = `cr`.category_id
							AND `ucp`.user_id = `u`.id
							ORDER BY `ucp`.permission_type DESC
							LIMIT 1
						)
					);"
			);
	}

	public function createViews() {
		$permission = ClassRegistry::init('Permission');
		$views = $this->getViewsSQL();
		foreach ($views as $view) {
			$permission->query($view);
		}
	}

	public static function getFunctionsSQL() {
		return array(
			"getGroupCategoryPermission" =>
				"DROP FUNCTION IF EXISTS getGroupCategoryPermission;
				CREATE FUNCTION `getGroupCategoryPermission`(`group_id` VARCHAR(36), `category_id` VARCHAR(36)) RETURNS varchar(36) CHARSET utf8
				    NO SQL
						BEGIN
						    DECLARE `permid` VARCHAR(36);
						    
								SELECT `gcp`.permission_id INTO `permid`
								FROM `groups_categories_permissions` gcp
								WHERE `gcp`.group_id = `group_id` 
								AND `gcp`.category_id = `category_id`;
								
						    RETURN `permid`;
						END;",

			"getGroupResourcePermission" =>
				"DROP FUNCTION IF EXISTS getGroupResourcePermission;
				CREATE FUNCTION `getGroupResourcePermission`(`group_id` VARCHAR(36), `resource_id` VARCHAR(36)) RETURNS varchar(36) CHARSET utf8
			    NO SQL
					BEGIN
					    DECLARE `permid` VARCHAR(36);

					    SELECT `grp`.permission_id INTO `permid`
							FROM `groups_resources_permissions` grp
							WHERE `grp`.group_id =  `group_id`
							AND `grp`.resource_id = `resource_id`;

					    RETURN `permid`;
					END;",

			"getUserCategoryPermission" =>
					"DROP FUNCTION IF EXISTS getUserCategoryPermission;
					CREATE FUNCTION `getUserCategoryPermission`(`user_id` VARCHAR(36), `category_id` VARCHAR(36)) RETURNS varchar(36) CHARSET utf8
					NO SQL
					BEGIN
				  DECLARE `permid` VARCHAR(36);
					
						SELECT `ucp`.permission_id INTO `permid`
						FROM `users_categories_permissions` ucp
						WHERE `ucp`.user_id =  `user_id`
						AND `ucp`.category_id = `category_id`;

				   RETURN `permid`;
				 	 END;",

				"getUserResourcePermission" =>
					"DROP FUNCTION IF EXISTS getUserResourcePermission;
						CREATE FUNCTION `getUserResourcePermission`(`user_id` VARCHAR(36), `resource_id` VARCHAR(36)) RETURNS varchar(36) CHARSET utf8
						NO SQL
						BEGIN
							DECLARE `permid` VARCHAR(36);
							
							SELECT `urp`.permission_id INTO `permid`
							FROM `users_resources_permissions` urp
							WHERE `urp`.user_id =  `user_id`
							AND `urp`.resource_id = `resource_id`;

							RETURN `permid`;
						END;"
		);
	}

	public function createFunctions() {
		$permission = ClassRegistry::init('Permission');
		$functions = $this->getFunctionsSQL();
		foreach ($functions as $f) {
			$permission->query($f);
		}
		// TODO : manage case where user is owner of the resource. What to do ? What should be the permission then ?
	}
}