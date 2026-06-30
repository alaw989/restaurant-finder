import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { api, get, post, buildParams, getBaseUrl } from '@/lib/api';

describe('buildParams (drops null/undefined, keeps falsy-but-valid)', () => {
    it('keeps string / number / boolean (incl. 0 / false / "") and drops null / undefined', () => {
        // 0 / false / '' are valid query values (e.g. ?page=0, ?open=false);
        // only null/undefined are dropped. A naive `if (value)` refactor would
        // wrongly drop them — this pins the contract.
        const q = buildParams({
            a: 1,
            b: 'x',
            e: true,
            page: 0,
            open: false,
            q: '',
            n: null,
            u: undefined,
        });
        expect(q.get('a')).toBe('1');
        expect(q.get('b')).toBe('x');
        expect(q.get('e')).toBe('true');
        expect(q.get('page')).toBe('0');
        expect(q.get('open')).toBe('false');
        expect(q.get('q')).toBe('');
        expect(q.has('n')).toBe(false);
        expect(q.has('u')).toBe(false);
    });

    it('produces an empty query for an all-null/undefined object', () => {
        expect(buildParams({ a: null, b: undefined }).toString()).toBe('');
    });
});

describe('getBaseUrl (client branch under jsdom)', () => {
    it('derives origin from window.location, never the hard-coded prod URL', () => {
        expect(getBaseUrl()).toBe(`${window.location.protocol}//${window.location.host}`);
        expect(getBaseUrl()).not.toBe('https://ipop360.vp-associates.com');
    });
});

describe('api() fetch wrapper', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });
    afterEach(() => vi.unstubAllGlobals());

    it('GETs a relative endpoint against getBaseUrl and returns parsed JSON', async () => {
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: true,
            json: async () => ({ hello: 'world' }),
        });

        const result = await get<{ hello: string }>('/api/restaurants');

        expect(fetch).toHaveBeenCalledOnce();
        const [url, init] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(url).toBe(`${getBaseUrl()}/api/restaurants`);
        expect((init as RequestInit).method).toBe('GET');
        expect(result).toEqual({ hello: 'world' });
    });

    it('throws the server message on a non-ok response', async () => {
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({ message: 'The cuisine field is required.' }),
        });

        await expect(api('/api/x')).rejects.toThrow('The cuisine field is required.');
    });

    it('falls back to a status-based message when the body has no message', async () => {
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: false,
            status: 500,
            json: async () => ({}),
        });

        await expect(api('/api/x')).rejects.toThrow('Request failed with status 500');
    });

    it('rethrows the original error when fetch itself rejects (network failure)', async () => {
        // The most common real-world failure (offline/DNS). The catch must
        // rethrow the original Error, not swallow or rewrap it.
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockRejectedValue(
            new TypeError('Failed to fetch'),
        );

        await expect(api('/api/x')).rejects.toThrow('Failed to fetch');
    });

    it('JSON-stringifies an object POST body with a json content type', async () => {
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: true,
            json: async () => ({}),
        });

        await post('/favorites/toggle', { id: 7 });

        const [, init] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0];
        const headers = (init as RequestInit).headers as Record<string, string>;
        expect((init as RequestInit).body).toBe(JSON.stringify({ id: 7 }));
        expect(headers['Content-Type']).toBe('application/json');
    });

    it('lets the browser set Content-Type for FormData (deletes the json header)', async () => {
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: true,
            json: async () => ({}),
        });

        const form = new FormData();
        form.append('file', 'x');

        await post('/upload', form);

        const [, init] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0];
        const headers = (init as RequestInit).headers as Record<string, string>;
        expect(headers['Content-Type']).toBeUndefined();
        expect((init as RequestInit).body).toBe(form);
    });

    it('merges caller headers and lets them override the json defaults', async () => {
        // Pins the spread order: {...defaults, ...headers} → caller wins.
        (globalThis.fetch as ReturnType<typeof vi.fn>).mockResolvedValue({
            ok: true,
            json: async () => ({}),
        });

        await api('/x', { headers: { 'X-CSRF-TOKEN': 'abc', Accept: 'text/plain' } });

        const [, init] = (fetch as ReturnType<typeof vi.fn>).mock.calls[0];
        const headers = (init as RequestInit).headers as Record<string, string>;
        expect(headers['X-CSRF-TOKEN']).toBe('abc'); // caller header kept
        expect(headers['Accept']).toBe('text/plain'); // caller overrides default
        expect(headers['Content-Type']).toBe('application/json'); // default retained
    });
});
