UPDATE wp_options SET option_value = REPLACE(option_value, '@HOST_LOCAL@', '@HOST_STAGING@') WHERE option_value LIKE 'http://%';
UPDATE wp_posts SET guid = REPLACE(guid, '@HOST_LOCAL@', '@HOST_STAGING@');
UPDATE wp_posts SET post_content = REPLACE(post_content, '@HOST_LOCAL@', '@HOST_STAGING@');
