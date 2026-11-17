// Run: node tests/wizard.js. Exercises the actual inline wizard functions.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../site-setup-wizard.php'), 'utf8');
new vm.Script(source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/<\?php[\s\S]*?\?>/g, 'null'));
const slice = (from, to) => source.slice(source.indexOf(from), source.indexOf(to, source.indexOf(from)));

(async () => {
	const messages = [];
	const requests = [];
	const context = vm.createContext({
		FormData: class { constructor() { this.data = {}; } append(k, v) { this.data[k] = v; } },
		ajaxurl: '/admin-ajax.php', nonce: 'test',
		write: (message, status) => messages.push([message, status]),
		barSet() {},
		fetch: async (url, { body }) => {
			requests.push(body.data.slug);
			if (body.data.slug === 'warning') return { json: async () => { throw new SyntaxError('Malformed JSON'); } };
			return { json: async () => ({ success: body.data.slug !== 'failed', data: { message: body.data.slug } }) };
		}
	});
	vm.runInContext(slice('\t\tfunction call(', "\n\t\tdocument.getElementById( 'ssw-confirm-wipe' )"), context);
	vm.runInContext(slice('\t\tfunction installPluginSlugs(', '\n\t\tfunction updateRetryButton('), context);
	const failed = await context.installPluginSlugs(['first', 'failed', 'warning', 'last']);
	assert.deepEqual(Array.from(failed), ['failed', 'warning']);
	assert.deepEqual(requests, ['first', 'failed', 'warning', 'last']);
	assert(messages.some(([text]) => text.startsWith('warning: request error:')));

	for (const keep of [true, false]) {
		const calls = [];
		context.document = { getElementById: () => ({ value: 'Home' }) };
		context.window = { confirm: () => keep };
		context.call = async (action, data) => {
			calls.push(data);
			return calls.length === 1 ? { success: false, data: { needs_confirmation: true, message: 'Existing homepage' } } : { success: true };
		};
		vm.runInContext(slice('\t\tfunction runStep3(', '\n\t\tvar step4Failed'), context);
		assert((await context.runStep3(false)).success);
		assert.equal(calls.length, 2);
		assert.equal(calls[1].keep_existing, keep ? 1 : 0);
		assert.equal(calls[1].force_new, keep ? 0 : 1);
	}
	console.log('Wizard queue isolation and homepage confirmation checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
