const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync, existsSync } = require('node:fs');

test('removed Community feed has no navigation, rendering, handlers, or public API', () => {
  const files = ['templates/web/app.html.twig', 'templates/web/partials/app/_sidebar.html.twig',
    'templates/web/partials/app/_mobile-header.html.twig', 'templates/web/partials/app/_tab-settings.html.twig',
    'public/assets/js/app.js', 'src/Web/Controller/AppController.php', 'src/Web/Controller/ApiController.php'];
  for (const file of files) {
    assert.doesNotMatch(readFileSync(file, 'utf8'), /tab=community|_tab-community|Community Updates|data-post-form|feed_posts|create_post|update_post|delete_post|like_post|add_comment|switchCommunitySubTab/, file);
  }
  assert.equal(existsSync('templates/web/partials/app/_tab-community.html.twig'), false);
  assert.doesNotMatch(readFileSync('templates/web/home.html.twig', 'utf8'), /Vibrant Community|community platform/);
});
