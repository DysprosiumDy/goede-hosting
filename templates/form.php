<?php
/**
 * Markup of a form built in Goede Hosting > Formulieren.
 *
 * Override: copy this file to <theme>/goede-hosting/form.php. Keep $args['hidden'],
 * the field names, the form action/enctype and the data-gh-* attributes (the
 * script uses them for steps and conditions); change the rest.
 *
 * $args (as for contact-form.php, plus):
 *  - steps      array  Steps: label + items (kind field|html, key / html).
 *  - has_files  bool   The form has a file field (multipart).
 *  - logic      array  key => conditional logic.
 *
 * Without JavaScript every step is shown at once and every field is shown; the
 * server applies the conditions anyway.
 *
 * @package GoedeHosting
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- the helpers, $args['hidden'] and the html items (wp_kses_post) are escaped.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- included inside goede_hosting_render_template(), so these are local variables.
$steps = $args['steps'];
$count = count( $steps );
?>
<form class="gh-form gh-form--builder" id="<?php echo esc_attr( $args['id'] ); ?>" action="<?php echo esc_url( $args['action'] ); ?>" method="post"<?php echo $args['has_files'] ? ' enctype="multipart/form-data"' : ''; ?> data-gh-form data-gh-steps="<?php echo esc_attr( (string) $count ); ?>">
	<?php if ( $args['notice'] ) : ?>
		<div class="gh-form__notice gh-form__notice--<?php echo esc_attr( $args['notice']['type'] ); ?>" id="<?php echo esc_attr( $args['notice']['id'] ); ?>" tabindex="-1" role="<?php echo 'error' === $args['notice']['type'] ? 'alert' : 'status'; ?>">
			<p><?php echo esc_html( $args['notice']['text'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 'success' !== ( $args['notice']['type'] ?? '' ) ) : ?>
		<?php echo $args['hidden']; ?>

		<?php if ( $count > 1 ) : ?>
			<div class="gh-form__progress" data-gh-progress hidden>
				<div class="gh-form__progress-text" aria-live="polite"></div>
				<div class="gh-form__progress-bar"><span></span></div>
			</div>
		<?php endif; ?>

		<?php foreach ( $steps as $i => $step ) : ?>
			<fieldset class="gh-form__step" data-gh-step="<?php echo esc_attr( (string) $i ); ?>"<?php echo '' !== $step['label'] ? ' data-gh-step-label="' . esc_attr( $step['label'] ) . '"' : ''; ?>>
				<?php if ( '' !== $step['label'] ) : ?>
					<legend class="gh-form__step-title"><?php echo esc_html( $step['label'] ); ?></legend>
				<?php else : ?>
					<legend class="screen-reader-text">
						<?php
						/* translators: 1: step number, 2: number of steps. */
						echo esc_html( sprintf( __( 'Stap %1$d van %2$d', 'goede-hosting' ), $i + 1, $count ) );
						?>
					</legend>
				<?php endif; ?>
				<div class="gh-form__grid">
					<?php foreach ( $step['items'] as $item ) : ?>
						<?php if ( 'html' === $item['kind'] ) : ?>
							<div class="gh-form__content"><?php echo $item['html']; ?></div>
							<?php continue; ?>
						<?php endif; ?>
						<?php
						$field = $args['fields'][ $item['key'] ];
						$logic = $args['logic'][ $item['key'] ] ?? array();
						$attrs = ' data-gh-field="' . esc_attr( $item['key'] ) . '"' . ( $logic ? ' data-gh-logic="' . esc_attr( (string) wp_json_encode( $logic ) ) . '"' : '' );
						if ( 'hidden' === $field['type'] ) {
							echo '<div class="gh-form__hidden"' . $attrs . '>' . goede_hosting_form_control( $field ) . '</div>';
							continue;
						}
						$is_group = in_array( $field['type'], array( 'radio', 'checkboxes' ), true );
						$req      = $field['required'] ? ' <span class="gh-form__req" aria-hidden="true">*</span>' : '';
						?>
						<div class="gh-form__field gh-form__field--<?php echo esc_attr( $field['type'] ); ?> gh-form__field--<?php echo esc_attr( $field['width'] ); ?>"<?php echo $attrs; ?>>
							<?php if ( $is_group ) : ?>
								<fieldset class="gh-form__choices"<?php echo '' !== $field['error'] ? ' aria-describedby="' . esc_attr( $field['error_id'] ) . '"' : ''; ?>>
									<legend class="gh-form__label"><?php echo esc_html( $field['label'] ) . $req; ?></legend>
									<?php echo goede_hosting_form_control( $field ); ?>
								</fieldset>
							<?php elseif ( 'consent' === $field['type'] ) : ?>
								<?php echo goede_hosting_form_control( $field ); ?>
							<?php else : ?>
								<label class="gh-form__label" for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['label'] ) . $req; ?></label>
								<?php echo goede_hosting_form_control( $field ); ?>
							<?php endif; ?>
							<?php if ( '' !== $field['help'] ) : ?>
								<span class="gh-form__help" id="<?php echo esc_attr( $field['id'] ); ?>-hulp"><?php echo esc_html( $field['help'] ); ?></span>
							<?php endif; ?>
							<?php if ( 'file' === $field['type'] ) : ?>
								<span class="gh-form__help">
									<?php
									/* translators: 1: extensions, 2: size in MB. */
									echo esc_html( sprintf( __( 'Toegestaan: %1$s, maximaal %2$s MB.', 'goede-hosting' ), implode( ', ', goede_hosting_form_field_extensions( $field ) ), number_format_i18n( $field['max_size'], 1 ) ) );
									?>
								</span>
							<?php endif; ?>
							<?php echo goede_hosting_contact_error( $field ); ?>
						</div>
					<?php endforeach; ?>
				</div>
				<?php if ( $count > 1 ) : ?>
					<div class="gh-form__nav" data-gh-nav hidden>
						<?php if ( $i > 0 ) : ?>
							<button type="button" class="gh-form__back" data-gh-back><?php esc_html_e( 'Vorige', 'goede-hosting' ); ?></button>
						<?php endif; ?>
						<?php if ( $i < $count - 1 ) : ?>
							<button type="button" class="gh-form__button gh-form__next wp-element-button" data-gh-next><?php esc_html_e( 'Volgende', 'goede-hosting' ); ?></button>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</fieldset>
		<?php endforeach; ?>

		<div class="gh-form__foot" data-gh-submit>
			<p class="gh-form__hint"><span aria-hidden="true">*</span> <?php esc_html_e( 'verplicht veld', 'goede-hosting' ); ?></p>
			<button class="gh-form__button wp-element-button" type="submit"><?php echo esc_html( $args['button_text'] ); ?></button>
		</div>
		<?php if ( '' !== $args['privacy_url'] ) : ?>
			<p class="gh-form__privacy">
				<?php
				printf(
					/* translators: %s: link to the privacy statement. */
					esc_html__( 'Lees in onze %s hoe we met je gegevens omgaan.', 'goede-hosting' ),
					'<a href="' . esc_url( $args['privacy_url'] ) . '">' . esc_html__( 'privacyverklaring', 'goede-hosting' ) . '</a>'
				);
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</form>
