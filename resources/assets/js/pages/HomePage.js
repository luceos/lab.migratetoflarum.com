import m from 'mithril';
import icon from '../helpers/icon';
import App from '../utils/App';
import Store from '../utils/Store';
import sortByAttribute from '../helpers/sortByAttribute';
import link from '../helpers/link';
import moment from 'moment';
import Rating from '../components/Rating';

export default {
    oninit(vnode) {
        vnode.state.url = '';
        vnode.state.hidden = false;
        vnode.state.loading = false;
        vnode.state.errors = [];
    },
    view(vnode) {
        const recentScans = Store.all('scans').sort(sortByAttribute('scanned_at', 'desc'));

        return m('.page-home', [
            m('h2.text-center', 'Check the configuration of your Flarum'),
            m('.row.justify-content-center', [
                m('form.col-md-6', {
                    onsubmit(event) {
                        event.preventDefault();

                        vnode.state.loading = true;
                        vnode.state.errors = [];

                        m.request({
                            method: 'post',
                            url: '/api/scans',
                            data: {
                                _token: App.csrfToken,
                                url: vnode.state.url,
                                hidden: vnode.state.hidden,
                            },
                        }).then(response => {
                            Store.load(response.data);

                            m.route.set('/scans/' + response.data.id);

                            vnode.state.loading = false;
                        }).catch(err => {
                            vnode.state.loading = false;

                            if (err.errors && err.errors.url) {
                                vnode.state.errors = err.errors.url;

                                return;
                            }

                            alert('An error occurred !');

                            console.error(err);
                        });
                    },
                }, [
                    m('.position-relative', [
                        m('.form-group.mt-3', m('.input-group', [
                            m('input.form-control[type=url]', {
                                className: vnode.state.errors.length ? 'is-invalid' : '',
                                placeholder: 'https://yourflarum.tld',
                                value: vnode.state.url,
                                oninput: m.withAttr('value', value => {
                                    vnode.state.url = value;
                                }),
                                disabled: vnode.state.loading,
                            }),
                            m('.input-group-append', m('button.btn.btn-primary[type=submit]', {
                                disabled: vnode.state.loading,
                            }, vnode.state.loading ? 'Processing...' : ['Scan ', icon('chevron-right')])),
                        ])),
                        vnode.state.errors.map(
                            error => m('.invalid-tooltip.d-block', {
                                onclick() {
                                    // Hide errors if you click on them
                                    vnode.state.errors = [];
                                },
                            }, error)
                        ),
                    ]),
                    m('.form-group.text-center', m('label', m('input[type=checkbox]', {
                        checked: vnode.state.hidden,
                        disabled: vnode.state.loading,
                        onchange() {
                            vnode.state.hidden = !vnode.state.hidden;
                        },
                    }), ' Do not show the results on the homepage')),
                    m('.page-recent', [
                        m('h5', 'Recent scans'),
                        m('.list-group.list-group-flush', recentScans.map(
                            scan => link('/scans/' + scan.id, {
                                className: 'list-group-item list-group-item-action',
                            }, [
                                m('span.float-right.text-muted', moment(scan.attributes.scanned_at).fromNow()),
                                m(Rating, {
                                    rating: scan.attributes.rating,
                                }),
                                ' ',
                                scan.relationships.website.data.attributes.name,
                                ' - ',
                                m('span.text-muted', scan.relationships.website.data.attributes.normalized_url.replace(/\/$/, '')),
                                (scan.attributes.hidden ? m('span.text-muted', {
                                    title: 'This scan won\'t show up for other users',
                                }, [' ', icon('eye-slash')]) : null),
                            ])
                        )),
                    ]),
                ]),
            ]),
        ]);
    },
}
