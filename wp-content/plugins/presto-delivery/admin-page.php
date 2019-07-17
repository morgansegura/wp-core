<div class="wrap">
	<h1><?php echo self::PAGE_TITLE; ?></h1>
	<form method="post" action="options.php" id="secret-form">
		<?php settings_fields( self::OPTION_GROUP ); ?>
		<?php do_settings_sections( self::OPTION_GROUP ); ?>
		<table class="form-table">
			<tbody>
				<tr valign="top">
					<th scope="row">Authentication Secret</th>
					<td>
						<input type="text"
									 name="<?php echo self::OPTION_NAME; ?>"
									 value="<?php echo $option_value; ?>"
									 disabled="true">
					</td>
				</tr>
			</tbody>
		</table>
		<p>
			<input type="submit"
						 name="submit"
						 id="submit"
						 class="button button-primary"
						 value="Regenerate">
		</p>
	</form>
</div>
