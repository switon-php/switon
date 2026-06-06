/*
SQLyog Ultimate v13.1.1 (64 bit)
MySQL - 5.7.27 : Database - switon
*********************************************************************
*/

/*!40101 SET NAMES utf8mb4 */;

/*!40101 SET SQL_MODE=''*/;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
CREATE DATABASE /*!32312 IF NOT EXISTS*/`switon` /*!40100 DEFAULT CHARACTER SET utf8mb4 */;

USE `switon`;

/*Table structure for table `admin` */

-- DROP TABLE IF EXISTS `admin`;

CREATE TABLE `admin` (
  `admin_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_name` varchar(32) CHARACTER SET ascii NOT NULL,
  `status` tinyint(4) NOT NULL DEFAULT 0,
  `type` tinyint(4) NOT NULL DEFAULT 1,
  `tag` int(11) NOT NULL DEFAULT 0,
  `email` varchar(64) CHARACTER SET ascii NOT NULL,
  `avatar_id` int(11) NOT NULL DEFAULT 0,
  `password` char(60) CHARACTER SET ascii NOT NULL,
  `white_ip` varchar(64) NOT NULL DEFAULT '*',
  `login_ip` char(15) CHARACTER SET ascii NOT NULL DEFAULT '',
  `login_time` int(11) NOT NULL DEFAULT 0,
  `session_id` varchar(64) NOT NULL DEFAULT '',
  `fail_count` int(11) NOT NULL DEFAULT 0,
  `failed_at` int(11) NOT NULL DEFAULT 0,
  `locked_until` int(11) NOT NULL DEFAULT 0,
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`admin_id`),
  UNIQUE KEY `admin_name` (`admin_name`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `admin` */

insert  into `admin`(`admin_id`,`admin_name`,`status`,`type`,`tag`,`email`,`avatar_id`,`password`,`white_ip`,`login_ip`,`login_time`,`session_id`,`fail_count`,`failed_at`,`locked_until`,`created_by`,`updated_by`,`created_at`,`updated_at`) values
(1,'admin',1,1,0,'admin@qq.com',0,'$2y$08$OqVCWx86wwDNiSJP7eob..fei6UhTEpKzknxCCXQQeSKktQGiy71G','*','192.168.1.47',0,'gz7ne3p9iwj15bm0sqehljpi0hscp0g2',0,0,0,'','admin',0,0);

/*Table structure for table `admin_action_log` */

-- DROP TABLE IF EXISTS `admin_action_log`;

CREATE TABLE `admin_action_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `admin_name` varchar(32) CHARACTER SET ascii NOT NULL,
  `method` varchar(15) CHARACTER SET ascii NOT NULL,
  `handler` varchar(64) CHARACTER SET ascii NOT NULL,
  `tag` int(11) NOT NULL DEFAULT 0,
  `url` varchar(128) NOT NULL,
  `data` text NOT NULL,
  `client_ip` char(15) CHARACTER SET ascii NOT NULL,
  `client_udid` char(16) CHARACTER SET ascii NOT NULL DEFAULT '',
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`admin_id`),
  KEY `handler` (`handler`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*Data for the table `admin_action_log` */

/*Table structure for table `admin_login_log` */

-- DROP TABLE IF EXISTS `admin_login_log`;

CREATE TABLE `admin_login_log` (
  `login_id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `admin_name` char(16) CHARACTER SET ascii NOT NULL,
  `client_ip` char(15) CHARACTER SET ascii NOT NULL,
  `client_udid` char(16) CHARACTER SET ascii NOT NULL,
  `user_agent` char(255) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`login_id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

/*Data for the table `admin_login_log` */

/*Table structure for table `dict_type` */

-- DROP TABLE IF EXISTS `dict_type`;

CREATE TABLE `dict_type` (
  `type_id` int(11) NOT NULL AUTO_INCREMENT,
  `type_code` varchar(64) CHARACTER SET ascii NOT NULL,
  `type_name` varchar(64) NOT NULL,
  `value_type` varchar(16) CHARACTER SET ascii NOT NULL DEFAULT 'string',
  `builtin` tinyint(4) NOT NULL DEFAULT 0,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`type_id`),
  UNIQUE KEY `type_code` (`type_code`),
  KEY `status_display_order` (`status`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `dict_type` */

insert  into `dict_type`(`type_id`,`type_code`,`type_name`,`value_type`,`builtin`,`status`,`remark`,`display_order`,`created_by`,`updated_by`,`created_at`,`updated_at`) values
(1,'common.status','通用状态','int',1,1,'通用启停状态',0,'admin','admin',0,0),
(2,'admin.status','管理员状态','int',1,1,'管理员账号状态',0,'admin','admin',0,0),
(3,'org.status','组织状态','int',1,1,'组织域启停状态',0,'admin','admin',0,0),
(4,'org.group.type','组织分组类型','string',1,1,'组织分组类型',0,'admin','admin',0,0),
(5,'dict.value.type','字典值类型','string',1,1,'字典项值类型',0,'admin','admin',0,0),
(6,'rbac.permission.derived','权限派生类型','int',1,1,'权限目录中的派生标记',0,'admin','admin',0,0),
(7,'file.scene','文件场景','string',1,1,'文件上传场景',0,'admin','admin',0,0),

/*Table structure for table `dict_item` */

-- DROP TABLE IF EXISTS `dict_item`;

CREATE TABLE `dict_item` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `type_id` int(11) NOT NULL,
  `item_code` varchar(64) CHARACTER SET ascii NOT NULL,
  `item_name` varchar(64) NOT NULL,
  `item_value` varchar(128) NOT NULL,
  `tag` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `is_default` tinyint(4) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `type_item_code` (`type_id`,`item_code`),
  UNIQUE KEY `type_item_value` (`type_id`,`item_value`),
  KEY `type_status_display_order` (`type_id`,`status`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `dict_item` */

insert  into `dict_item`(`item_id`,`type_id`,`item_code`,`item_name`,`item_value`,`tag`,`status`,`is_default`,`display_order`,`remark`,`created_by`,`updated_by`,`created_at`,`updated_at`) values
(1,1,'disabled','禁用','0',0,1,0,0,'','admin','admin',0,0),
(2,1,'enabled','启用','1',0,1,1,1,'','admin','admin',0,0),
(3,2,'init','未激活','0',0,1,0,0,'','admin','admin',0,0),
(4,2,'active','激活','1',0,1,1,1,'','admin','admin',0,0),
(5,2,'locked','锁定','2',0,1,0,2,'','admin','admin',0,0),
(6,3,'disabled','停用','0',0,1,0,0,'','admin','admin',0,0),
(7,3,'enabled','启用','1',0,1,1,1,'','admin','admin',0,0),
(8,4,'default','默认','default',0,1,1,0,'','admin','admin',0,0),
(9,4,'project','项目','project',0,1,0,1,'','admin','admin',0,0),
(10,4,'temporary','临时','temporary',0,1,0,2,'','admin','admin',0,0),
(11,5,'string','字符串','string',0,1,1,0,'','admin','admin',0,0),
(12,5,'int','整数','int',0,1,0,1,'','admin','admin',0,0),
(13,6,'direct','仅权限','0',0,1,1,0,'','admin','admin',0,0),
(14,6,'derived','仅查看','1',0,1,0,1,'','admin','admin',0,0),
(15,7,'asset','通用文件','asset',0,1,1,0,'','admin','admin',0,0),
(16,7,'avatar','头像','avatar',0,1,0,1,'','admin','admin',0,0),
(17,7,'document','文档','document',0,1,0,2,'','admin','admin',0,0),
(18,7,'notice_cover','公告封面','notice_cover',0,1,0,3,'','admin','admin',0,0),
(19,7,'notice_attachment','公告附件','notice_attachment',0,1,0,4,'','admin','admin',0,0);

/*Table structure for table `file_asset` */

-- DROP TABLE IF EXISTS `file_asset`;

CREATE TABLE `file_asset` (
  `asset_id` int(11) NOT NULL AUTO_INCREMENT,
  `scene` varchar(32) CHARACTER SET ascii NOT NULL DEFAULT 'asset',
  `asset_name` varchar(128) NOT NULL,
  `asset_ext` varchar(16) CHARACTER SET ascii NOT NULL DEFAULT '',
  `mime_type` varchar(128) CHARACTER SET ascii NOT NULL DEFAULT '',
  `asset_size` int(11) NOT NULL DEFAULT 0,
  `asset_hash` char(32) CHARACTER SET ascii NOT NULL DEFAULT '',
  `asset_path` varchar(255) CHARACTER SET ascii NOT NULL,
  `asset_url` varchar(255) CHARACTER SET ascii NOT NULL,
  `remark` varchar(255) NOT NULL DEFAULT '',
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`asset_id`),
  UNIQUE KEY `asset_path` (`asset_path`),
  KEY `scene_created_at` (`scene`,`created_at`),
  KEY `asset_ext_created_at` (`asset_ext`,`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `menu_group` */

-- DROP TABLE IF EXISTS `menu_group`;

CREATE TABLE `menu_group` (
  `group_id` int(11) NOT NULL AUTO_INCREMENT,
  `group_name` varchar(64) NOT NULL,
  `icon` varchar(32) CHARACTER SET ascii NOT NULL,
  `display_order` tinyint(4) NOT NULL,
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `group_name` (`group_name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `menu_group` */

insert  into `menu_group`(`group_id`,`group_name`,`icon`,`display_order`,`created_by`,`updated_by`,`created_at`,`updated_at`) values
(3,'权限管理','el-icon-lock',0,'admin','admin',0,0),
(5,'系统管理','el-icon-setting',0,'admin','admin',0,0),
(4,'日志管理','el-icon-document',0,'admin','admin',0,0),
(8,'文件管理','el-icon-folder',0,'admin','admin',0,0),
(7,'字典管理','el-icon-collection',0,'admin','admin',0,0),
(2,'菜单管理','el-icon-menu',0,'admin','admin',0,0),
(6,'组织管理','el-icon-s-operation',0,'admin','admin',0,0),
(1,'个人中心','el-icon-user',1,'admin','admin',0,0);

/*Table structure for table `menu_item` */

-- DROP TABLE IF EXISTS `menu_item`;

CREATE TABLE `menu_item` (
  `item_id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(64) NOT NULL,
  `group_id` tinyint(4) NOT NULL,
  `display_order` tinyint(4) NOT NULL,
  `url` varchar(128) NOT NULL,
  `icon` varchar(32) CHARACTER SET ascii NOT NULL,
  `permission_code` varchar(64) NOT NULL DEFAULT '',
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`item_id`),
  UNIQUE KEY `group_item_name` (`group_id`,`item_name`),
  UNIQUE KEY `group_id` (`group_id`,`url`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `menu_item` */

insert  into `menu_item`(`item_id`,`item_name`,`group_id`,`display_order`,`url`,`icon`,`permission_code`,`created_by`,`updated_by`,`created_at`,`updated_at`) values
(8,'角色权限',3,0,'/rbac/role-permission/index','el-icon-connection','rbac.role-permission::index','admin','admin',0,0),
(4,'用户',3,3,'/rbac/admin/index','el-icon-user','rbac.admin::index','admin','admin',0,0),
(9,'系统信息',5,0,'/system/information/index','el-icon-data-analysis','system.information::index','admin','admin',0,0),
(5,'角色',3,0,'/rbac/role/index','el-icon-s-check','rbac.role::index','admin','admin',0,0),
(6,'权限',3,0,'/rbac/permission/index','el-icon-key','rbac.permission::index','admin','admin',0,0),
(7,'用户角色',3,0,'/rbac/admin-role/index','el-icon-link','rbac.admin-role::index','admin','admin',0,0),
(10,'登录日志',4,0,'/admin/login-log/index','el-icon-date','admin.login-log::index','admin','admin',0,0),
(11,'菜单组',2,0,'/menu/group/index','el-icon-folder-opened','menu.group::index','admin','admin',0,0),
(12,'菜单项',2,0,'/menu/item/index','el-icon-document-copy','menu.item::index','admin','admin',0,0),
(14,'组织部门',6,0,'/org/department/index','el-icon-office-building','org.department::index','admin','admin',0,0),
(15,'组织岗位',6,0,'/org/position/index','el-icon-postcard','org.position::index','admin','admin',0,0),
(16,'组织分组',6,0,'/org/group/index','el-icon-collection-tag','org.group::index','admin','admin',0,0),
(17,'人员部门',6,0,'/org/staff-department/index','el-icon-user-solid','org.staff-department::index','admin','admin',0,0),
(18,'人员岗位',6,0,'/org/staff-position/index','el-icon-user','org.staff-position::index','admin','admin',0,0),
(19,'人员分组',6,0,'/org/staff-group/index','el-icon-user-filled','org.staff-group::index','admin','admin',0,0),
(20,'字典类型',7,0,'/dict/type/index','el-icon-notebook-1','dict.type::index','admin','admin',0,0),
(21,'字典项',7,0,'/dict/item/index','el-icon-tickets','dict.item::index','admin','admin',0,0),
(22,'文件资产',8,0,'/file/asset/index','el-icon-folder','file.asset::index','admin','admin',0,0),
(13,'动作日志',4,0,'/admin/action-log/index','el-icon-document','admin.action-log::index','mark','mark',0,0),
(1,'最近登录',1,0,'/admin/login-log/latest','el-icon-date','admin.login-log::latest','admin','admin',0,0),
(2,'最近操作',1,0,'/admin/action-log/latest','el-icon-document','admin.action-log::latest','admin','admin',0,0),
(3,'修改密码',1,0,'/admin/password/change','el-icon-lock','admin.password::change','admin','admin',0,0);

/*Table structure for table `org_department` */

-- DROP TABLE IF EXISTS `org_department`;

CREATE TABLE `org_department` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_code` varchar(64) CHARACTER SET ascii NOT NULL,
  `department_name` varchar(64) NOT NULL,
  `parent_id` int(11) NOT NULL DEFAULT 0,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `department_code` (`department_code`),
  KEY `parent_id` (`parent_id`),
  KEY `status_display_order` (`status`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `org_position` */

-- DROP TABLE IF EXISTS `org_position`;

CREATE TABLE `org_position` (
  `position_id` int(11) NOT NULL AUTO_INCREMENT,
  `position_code` varchar(64) CHARACTER SET ascii NOT NULL,
  `position_name` varchar(64) NOT NULL,
  `display_order` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`position_id`),
  UNIQUE KEY `position_code` (`position_code`),
  KEY `status_display_order` (`status`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `org_group` */

-- DROP TABLE IF EXISTS `org_group`;

CREATE TABLE `org_group` (
  `group_id` int(11) NOT NULL AUTO_INCREMENT,
  `group_code` varchar(64) CHARACTER SET ascii NOT NULL,
  `group_name` varchar(64) NOT NULL,
  `type` varchar(32) CHARACTER SET ascii NOT NULL DEFAULT '',
  `display_order` int(11) NOT NULL DEFAULT 0,
  `status` tinyint(4) NOT NULL DEFAULT 1,
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`group_id`),
  UNIQUE KEY `group_code` (`group_code`),
  KEY `status_display_order` (`status`,`display_order`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `org_staff_department` */

-- DROP TABLE IF EXISTS `org_staff_department`;

CREATE TABLE `org_staff_department` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `is_primary` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_department` (`staff_id`,`department_id`),
  KEY `department_id` (`department_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `org_staff_position` */

-- DROP TABLE IF EXISTS `org_staff_position`;

CREATE TABLE `org_staff_position` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `position_id` int(11) NOT NULL,
  `is_primary` tinyint(4) NOT NULL DEFAULT 0,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_position` (`staff_id`,`position_id`),
  KEY `position_id` (`position_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `org_staff_group` */

-- DROP TABLE IF EXISTS `org_staff_group`;

CREATE TABLE `org_staff_group` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `staff_id` int(11) NOT NULL,
  `group_id` int(11) NOT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `staff_group` (`staff_id`,`group_id`),
  KEY `group_id` (`group_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Table structure for table `metadata_constant` */

-- DROP TABLE IF EXISTS `metadata_constant`;

CREATE TABLE `metadata_constant` (
  `id` varchar(32) NOT NULL,
  `constants` tinytext NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `metadata_constant` */

/*Table structure for table `rbac_admin_role` */

-- DROP TABLE IF EXISTS `rbac_admin_role`;

CREATE TABLE `rbac_admin_role` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `admin_id` int(11) NOT NULL,
  `admin_name` varchar(32) CHARACTER SET ascii NOT NULL,
  `role_id` int(11) NOT NULL,
  `role_code` char(32) NOT NULL,
  `created_by` char(16) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id_role_id` (`admin_id`,`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `rbac_admin_role` */

/*Table structure for table `rbac_permission` */

-- DROP TABLE IF EXISTS `rbac_permission`;

CREATE TABLE `rbac_permission` (
  `permission_id` int(11) NOT NULL AUTO_INCREMENT,
  `permission_code` varchar(64) CHARACTER SET ascii NOT NULL,
  `authorize` varchar(64) CHARACTER SET ascii NOT NULL,
  `assignable` tinyint(4) NOT NULL DEFAULT 1,
  `derived` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0=注解显式(scan) 1=通配派生(catalog)',
  `display_name` varchar(128) NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permission_code` (`permission_code`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `rbac_permission` */

/*Table structure for table `rbac_role` */

-- DROP TABLE IF EXISTS `rbac_role`;

CREATE TABLE `rbac_role` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_code` varchar(64) NOT NULL,
  `role_name` varchar(64) NOT NULL,
  `builtin` tinyint(4) NOT NULL DEFAULT 1,
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  `permissions` varchar(16384) CHARACTER SET ascii NOT NULL DEFAULT '',
  `created_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `updated_by` varchar(32) CHARACTER SET ascii NOT NULL,
  `created_at` int(11) NOT NULL,
  `updated_at` int(11) NOT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_code` (`role_code`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `rbac_role` */

insert  into `rbac_role`(`role_id`,`role_code`,`role_name`,`builtin`,`enabled`,`permissions`,`created_by`,`updated_by`,`created_at`,`updated_at`) values
(1,'superuser','超级管理员',1,1,'','admin','admin',0,0),
(2,'rbac','权限管理员',0,1,'','admin','admin',0,0),
(3,'menu','菜单管理员',0,1,'','admin','admin',0,0);

/*Table structure for table `rbac_role_permission` */

-- DROP TABLE IF EXISTS `rbac_role_permission`;

CREATE TABLE `rbac_role_permission` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `role_id` int(11) NOT NULL,
  `permission_id` int(11) NOT NULL,
  `created_by` char(16) NOT NULL,
  `created_at` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permission_id_role_id` (`permission_id`,`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `rbac_role_permission` */

/*Table structure for table `test` */

-- DROP TABLE IF EXISTS `test`;

CREATE TABLE `test` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `balance` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4;

/*Data for the table `test` */

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
