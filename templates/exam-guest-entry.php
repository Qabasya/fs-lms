<?php
/**
 * Шаблон гостевой регистрации на публичный экзамен
 */
declare(strict_types=1);
?>

<div class="fs-lms-exam-guest-entry">
	<h1><?php esc_html_e('Регистрация на экзамен', 'fs-lms'); ?></h1>

	<form id="exam-guest-form" class="exam-guest-form" method="post">
		<?php wp_nonce_field('exam_guest_register', 'security'); ?>

		<div class="form-group">
			<label for="guest-name"><?php esc_html_e('ФИО:', 'fs-lms'); ?></label>
			<input
				type="text"
				id="guest-name"
				name="guest_name"
				class="form-control"
				required
				placeholder="Иван Петров"
			/>
		</div>

		<div class="form-group">
			<label for="guest-phone"><?php esc_html_e('Телефон:', 'fs-lms'); ?></label>
			<input
				type="tel"
				id="guest-phone"
				name="guest_phone"
				class="form-control"
				required
				placeholder="+7 (999) 999-99-99"
			/>
		</div>

		<div class="form-group">
			<label for="guest-email"><?php esc_html_e('Email:', 'fs-lms'); ?></label>
			<input
				type="email"
				id="guest-email"
				name="guest_email"
				class="form-control"
				required
				placeholder="guest@example.com"
			/>
		</div>

		<div class="form-group">
			<label for="guest-school"><?php esc_html_e('Школа:', 'fs-lms'); ?></label>
			<input
				type="text"
				id="guest-school"
				name="guest_school"
				class="form-control"
				placeholder="СОШ №1"
			/>
		</div>

		<div class="form-group">
			<label for="guest-grade"><?php esc_html_e('Класс:', 'fs-lms'); ?></label>
			<select id="guest-grade" name="guest_grade" class="form-control">
				<option value=""><?php esc_html_e('Выберите класс', 'fs-lms'); ?></option>
				<option value="9"><?php esc_html_e('9 класс', 'fs-lms'); ?></option>
				<option value="11"><?php esc_html_e('11 класс', 'fs-lms'); ?></option>
			</select>
		</div>

		<div class="form-group consent">
			<label>
				<input type="checkbox" name="consent_pd" required />
				<?php esc_html_e('Я согласен на обработку персональных данных', 'fs-lms'); ?>
			</label>
		</div>

		<button type="submit" class="button button-primary button-large">
			<?php esc_html_e('Зарегистрироваться', 'fs-lms'); ?>
		</button>
	</form>

	<div id="exam-guest-success" class="exam-success-message" style="display:none;">
		<p><?php esc_html_e('Спасибо за регистрацию! Вам отправлено письмо с ссылкой входа.', 'fs-lms'); ?></p>
	</div>

	<div id="exam-guest-error" class="exam-error-message" style="display:none;"></div>
</div>

<style>
.fs-lms-exam-guest-entry {
	max-width: 500px;
	margin: 40px auto;
	padding: 30px;
	background: #f9f9f9;
	border-radius: 8px;
}

.form-group {
	margin-bottom: 20px;
}

.form-group label {
	display: block;
	margin-bottom: 8px;
	font-weight: 500;
	color: #333;
}

.form-control {
	width: 100%;
	padding: 10px;
	border: 1px solid #ddd;
	border-radius: 4px;
	font-size: 14px;
}

.consent label {
	display: flex;
	align-items: center;
	font-weight: normal;
}

.consent input {
	margin-right: 10px;
}

.exam-success-message {
	padding: 20px;
	background: #d4edda;
	color: #155724;
	border-radius: 4px;
	border: 1px solid #c3e6cb;
}

.exam-error-message {
	padding: 20px;
	background: #f8d7da;
	color: #721c24;
	border-radius: 4px;
	border: 1px solid #f5c6cb;
	margin-bottom: 20px;
}
</style>

<script>
document.getElementById('exam-guest-form').addEventListener('submit', async function(e) {
	e.preventDefault();

	const formData = new FormData(this);
	const data = Object.fromEntries(formData);

	try {
		const response = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded',
			},
			body: new URLSearchParams({
				action: 'exam_guest_register',
				security: data.security,
				guest_name: data.guest_name,
				guest_phone: data.guest_phone,
				guest_email: data.guest_email,
				guest_school: data.guest_school || '',
				guest_grade: data.guest_grade || '',
			})
		});

		const result = await response.json();

		if (result.success) {
			document.getElementById('exam-guest-form').style.display = 'none';
			document.getElementById('exam-guest-success').style.display = 'block';
		} else {
			document.getElementById('exam-guest-error').textContent = result.data?.message || 'Ошибка регистрации';
			document.getElementById('exam-guest-error').style.display = 'block';
		}
	} catch (error) {
		document.getElementById('exam-guest-error').textContent = 'Ошибка сети: ' + error.message;
		document.getElementById('exam-guest-error').style.display = 'block';
	}
});
</script>
