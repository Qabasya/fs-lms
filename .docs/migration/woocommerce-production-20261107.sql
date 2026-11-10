-- One-time production import; stop all order writers first. Run in mariadb batch mode
-- without --force. An error closes the connection and rolls back the transaction.
USE fs_lms;

CREATE TEMPORARY TABLE migration_assert (ok TINYINT NOT NULL CHECK (ok = 1));
CREATE TEMPORARY TABLE item_map (old_id BIGINT UNSIGNED PRIMARY KEY, new_id BIGINT UNSIGNED NOT NULL UNIQUE);
CREATE TEMPORARY TABLE note_map (old_id BIGINT UNSIGNED PRIMARY KEY, new_id BIGINT UNSIGNED NOT NULL UNIQUE);

START TRANSACTION;

-- Fail closed if this is not the reviewed source or if anything was already imported.
INSERT INTO migration_assert SELECT COUNT(*) = 794 FROM fs_wc_source_20261107.wp_wc_orders;
INSERT INTO migration_assert SELECT COUNT(*) = 911 FROM fs_wc_source_20261107.wp_woocommerce_order_items i JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=i.order_id;
INSERT INTO migration_assert SELECT COUNT(*) = 7512 FROM fs_wc_source_20261107.wp_woocommerce_order_itemmeta m JOIN fs_wc_source_20261107.wp_woocommerce_order_items i ON i.order_item_id=m.order_item_id JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=i.order_id;
INSERT INTO migration_assert SELECT COUNT(*) = 9450 FROM fs_wc_source_20261107.wp_wc_orders_meta m JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=m.order_id;
INSERT INTO migration_assert SELECT COUNT(*) = 796 FROM fs_wc_source_20261107.wp_wc_order_addresses a JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=a.order_id;
INSERT INTO migration_assert SELECT COUNT(*) = 2284 FROM fs_wc_source_20261107.wp_comments c JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=c.comment_post_ID WHERE c.comment_type='order_note';
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders s JOIN wp_wc_orders t ON t.id=s.id;
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders s JOIN wp_posts t ON t.ID=s.id;
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders s JOIN wp_wc_orders t ON BINARY t.transaction_id=BINARY s.transaction_id WHERE s.transaction_id IS NOT NULL AND s.transaction_id<>'';
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders s JOIN wp_wc_orders t ON t.date_created_gmt=s.date_created_gmt AND t.total_amount=s.total_amount AND t.currency=s.currency;

INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders WHERE parent_order_id IS NOT NULL AND parent_order_id <> 0;
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_comments c JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=c.comment_post_ID WHERE c.comment_type='order_note' AND (c.comment_parent <> 0 OR c.user_id <> 0);
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_commentmeta m JOIN fs_wc_source_20261107.wp_comments c ON c.comment_ID=m.comment_id JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=c.comment_post_ID WHERE c.comment_type='order_note';
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders o LEFT JOIN fs_wc_source_20261107.wp_wc_order_operational_data d ON d.order_id=o.id WHERE d.order_id IS NULL;
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_wc_orders o LEFT JOIN fs_wc_source_20261107.wp_users s ON s.ID=o.customer_id LEFT JOIN wp_users t ON t.ID=o.customer_id WHERE o.customer_id > 0 AND (s.ID IS NULL OR t.ID IS NULL OR BINARY s.user_login <> BINARY t.user_login);
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_woocommerce_order_itemmeta m JOIN fs_wc_source_20261107.wp_woocommerce_order_items i ON i.order_item_id=m.order_item_id JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=i.order_id LEFT JOIN wp_posts p ON p.ID=CAST(m.meta_value AS UNSIGNED) WHERE m.meta_key='_product_id' AND m.meta_value REGEXP '^[1-9][0-9]*$' AND p.ID IS NOT NULL;
INSERT INTO migration_assert SELECT COUNT(*) = 0 FROM fs_wc_source_20261107.wp_woocommerce_order_itemmeta m JOIN fs_wc_source_20261107.wp_woocommerce_order_items i ON i.order_item_id=m.order_item_id JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=i.order_id WHERE m.meta_key='_variation_id' AND m.meta_value REGEXP '^[1-9][0-9]*$';

SET @item_offset = (SELECT COALESCE(MAX(order_item_id),0) FROM wp_woocommerce_order_items);
SET @note_offset = (SELECT COALESCE(MAX(comment_ID),0) FROM wp_comments);
INSERT INTO item_map SELECT i.order_item_id, @item_offset + i.order_item_id FROM fs_wc_source_20261107.wp_woocommerce_order_items i JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=i.order_id;
INSERT INTO note_map SELECT c.comment_ID, @note_offset + c.comment_ID FROM fs_wc_source_20261107.wp_comments c JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=c.comment_post_ID WHERE c.comment_type='order_note';

INSERT INTO wp_posts (ID,post_author,post_date,post_date_gmt,post_content,post_title,post_excerpt,post_status,comment_status,ping_status,post_password,post_name,to_ping,pinged,post_modified,post_modified_gmt,post_content_filtered,post_parent,guid,menu_order,post_type,post_mime_type,comment_count)
SELECT id,0,COALESCE(date_created_gmt,'1970-01-01 00:00:00'),COALESCE(date_created_gmt,'1970-01-01 00:00:00'),'','','','draft','closed','closed','','','','',COALESCE(date_created_gmt,'1970-01-01 00:00:00'),COALESCE(date_created_gmt,'1970-01-01 00:00:00'),'',0,'',0,'shop_order_placehold','',0 FROM fs_wc_source_20261107.wp_wc_orders;

INSERT INTO wp_wc_orders (id,status,currency,type,tax_amount,total_amount,customer_id,billing_email,date_created_gmt,date_updated_gmt,parent_order_id,payment_method,payment_method_title,transaction_id,ip_address,user_agent,customer_note)
SELECT id,status,currency,type,tax_amount,total_amount,customer_id,billing_email,date_created_gmt,date_updated_gmt,parent_order_id,payment_method,payment_method_title,transaction_id,ip_address,user_agent,customer_note FROM fs_wc_source_20261107.wp_wc_orders;
INSERT INTO wp_wc_orders_meta (order_id,meta_key,meta_value) SELECT m.order_id,m.meta_key,m.meta_value FROM fs_wc_source_20261107.wp_wc_orders_meta m JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=m.order_id;
INSERT INTO wp_wc_order_addresses (order_id,address_type,first_name,last_name,company,address_1,address_2,city,state,postcode,country,email,phone) SELECT a.order_id,a.address_type,a.first_name,a.last_name,a.company,a.address_1,a.address_2,a.city,a.state,a.postcode,a.country,a.email,a.phone FROM fs_wc_source_20261107.wp_wc_order_addresses a JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=a.order_id;
INSERT INTO wp_wc_order_operational_data (order_id,created_via,woocommerce_version,prices_include_tax,coupon_usages_are_counted,download_permission_granted,cart_hash,new_order_email_sent,order_key,order_stock_reduced,date_paid_gmt,date_completed_gmt,shipping_tax_amount,shipping_total_amount,discount_tax_amount,discount_total_amount,recorded_sales)
SELECT d.order_id,d.created_via,d.woocommerce_version,d.prices_include_tax,d.coupon_usages_are_counted,d.download_permission_granted,d.cart_hash,d.new_order_email_sent,d.order_key,d.order_stock_reduced,d.date_paid_gmt,d.date_completed_gmt,d.shipping_tax_amount,d.shipping_total_amount,d.discount_tax_amount,d.discount_total_amount,d.recorded_sales FROM fs_wc_source_20261107.wp_wc_order_operational_data d JOIN fs_wc_source_20261107.wp_wc_orders o ON o.id=d.order_id;
INSERT INTO wp_woocommerce_order_items (order_item_id,order_item_name,order_item_type,order_id) SELECT map.new_id,i.order_item_name,i.order_item_type,i.order_id FROM fs_wc_source_20261107.wp_woocommerce_order_items i JOIN item_map map ON map.old_id=i.order_item_id;
INSERT INTO wp_woocommerce_order_itemmeta (order_item_id,meta_key,meta_value) SELECT map.new_id,m.meta_key,CASE WHEN m.meta_key IN ('_product_id','_variation_id') THEN '0' ELSE m.meta_value END FROM fs_wc_source_20261107.wp_woocommerce_order_itemmeta m JOIN item_map map ON map.old_id=m.order_item_id;
INSERT INTO wp_comments (comment_ID,comment_post_ID,comment_author,comment_author_email,comment_author_url,comment_author_IP,comment_date,comment_date_gmt,comment_content,comment_karma,comment_approved,comment_agent,comment_type,comment_parent,user_id)
SELECT map.new_id,c.comment_post_ID,c.comment_author,c.comment_author_email,c.comment_author_url,c.comment_author_IP,c.comment_date,c.comment_date_gmt,c.comment_content,c.comment_karma,c.comment_approved,c.comment_agent,c.comment_type,c.comment_parent,c.user_id FROM fs_wc_source_20261107.wp_comments c JOIN note_map map ON map.old_id=c.comment_ID;

INSERT INTO migration_assert SELECT COUNT(*)=794 FROM wp_wc_orders t JOIN fs_wc_source_20261107.wp_wc_orders s ON s.id=t.id WHERE t.status <=> s.status AND t.total_amount <=> s.total_amount AND t.transaction_id <=> s.transaction_id AND t.customer_id <=> s.customer_id;
INSERT INTO migration_assert SELECT COUNT(*)=911 FROM wp_woocommerce_order_items t JOIN item_map m ON m.new_id=t.order_item_id;
INSERT INTO migration_assert SELECT COUNT(*)=7512 FROM wp_woocommerce_order_itemmeta t JOIN item_map m ON m.new_id=t.order_item_id;
INSERT INTO migration_assert SELECT COUNT(*)=2284 FROM wp_comments t JOIN note_map m ON m.new_id=t.comment_ID;
COMMIT;
SELECT 'PRODUCTION IMPORT OK' AS result, (SELECT COUNT(*) FROM item_map) AS items, (SELECT COUNT(*) FROM note_map) AS notes;
