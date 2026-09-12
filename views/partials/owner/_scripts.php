<script>
  // Every fetch in owner.js builds its URL from this, so the portal works the
  // same at a domain root and under an XAMPP subdirectory.
  window.PICKLERS_BASE_URL = <?= json_encode(\Picklers\Helpers\Url::base(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= \Picklers\Helpers\Url::asset('js/ux-core.js', true) ?>"></script>
<script src="<?= \Picklers\Helpers\Url::asset('js/owner.js', true) ?>"></script>
