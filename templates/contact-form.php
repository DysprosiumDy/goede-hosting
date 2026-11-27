<?php
/**
 * Contact form markup.
 *
 * Override: copy this file to <theme>/goede-hosting/contact-form.php. Keep the
 * $args['hidden'] fields, the field names and the form action; change the rest.
 *
 * $args:
 *  - id           string  Form id (anchor the visitor returns to).
 *  - action       string  Form action URL.
 *  - button_text  string
 *  - notice       array|null  type (success|error) + text.
 *  - hidden       string  Hidden fields (nonce, honeypot, timestamp) – print as is.
 *  - fields       array   key => field (label, type, required, value, error, id, error_id …).
 *  - rows         array   Layout: group, label, required, fields (keys) per row.
 *
 * Helpers: goede_hosting_contact_control( $field, $attrs ) prints the input or
 * textarea, goede_hosting_contact_error( $field, $class ) the error message.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- the helpers and $args['hidden'] return escaped HTML.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside goede_hosting_render_template(), so these are local variables.
?>
<form class="gh-form" id="<?php echo esc_attr( $args['id'] ); ?>" action="<?php echo esc_url( $args['action'] ); ?>" method="post">
	<?php if ( $args['notice'] ) : ?>
		<div class="gh-form__notice gh-form__notice--<?php echo esc_attr( $args['notice']['type'] ); ?>" role="<?php echo 'error' === $args['notice']['type'] ? 'alert' : 'status'; ?>">
			<p><?php echo esc_html( $args['notice']['text'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php echo $args['hidden']; ?>

	<?php foreach ( $args['rows'] as $row ) : ?>
		<?php if ( '' !== $row['group'] ) : ?>
			<fieldset class="gh-form__group">
				<legend class="gh-form__label"><?php echo esc_html( $row['label'] ); ?><?php echo $row['required'] ? ' <span class="gh-form__req" aria-hidden="true">*</span>' : ''; ?></legend>
				<div class="gh-form__row">
					<?php foreach ( $row['fields'] as $key ) : ?>
						<?php $field = $args['fields'][ $key ]; ?>
						<label class="gh-form__field">
							<span class="screen-reader-text"><?php echo esc_html( $field['label'] ); ?></span>
							<?php echo goede_hosting_contact_control( $field, '' === $field['placeholder'] ? array( 'placeholder' => $field['label'] ) : array() ); ?>
							<?php echo goede_hosting_contact_error( $field ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</fieldset>
		<?php else : ?>
			<?php $field = $args['fields'][ $row['fields'][0] ]; ?>
			<label class="gh-form__field">
				<span class="gh-form__label"><?php echo esc_html( $field['label'] ); ?><?php echo $field['required'] ? ' <span class="gh-form__req" aria-hidden="true">*</span>' : ''; ?></span>
				<?php echo goede_hosting_contact_control( $field ); ?>
				<?php echo goede_hosting_contact_error( $field ); ?>
			</label>
		<?php endif; ?>
	<?php endforeach; ?>

	<div class="gh-form__foot">
		<p class="gh-form__hint"><span aria-hidden="true">*</span> <?php esc_html_e( 'verplicht veld', 'goede-hosting' ); ?></p>
		<button class="gh-form__button wp-element-button" type="submit"><?php echo esc_html( $args['button_text'] ); ?></button>
	</div>
</form>
