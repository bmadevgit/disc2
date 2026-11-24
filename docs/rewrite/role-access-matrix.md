# Role and identity contract

The example case DEMO-001 belongs to the demo owner, in the demo group and unit. The suite also seeds a different group in that unit and a case in a second unit. Dashboard counts and case detail follow distinct access rules.

| Session / assignment | Index | Dashboard | Read case | Create / edit | Identity in subject view and search |
| --- | --- | --- | --- | --- | --- |
| Anonymous | 302 login | 302 login | 302 login | 302 login | Login required |
| Admin or configured super-admin | All cases | All units with deadlines | All | Yes / all cases | Stored name, reference fallback |
| Executive | 302 dashboard | All-unit aggregates | 403 | No / no | No subject detail |
| Unit head | 302 dashboard | Own unit aggregates | 403 | No / no | No subject detail |
| Group head | Own unit AND group | 302 index | Matching unit and group only | No / no | Reference or sequence label |
| Case owner | Current ownerships | 302 index | Active ownership only | Yes / active ownership only | Reference or sequence label |
| Unrelated case owner | Own list, DEMO-001 absent | 302 index | 403 for DEMO-001 | Yes / 403 for DEMO-001 | No subject detail on this case |
| No active assignment or session unit | 200 assignment notice | 302 index | 403 | No / no | No subject detail |

An unassigned authenticated BMA user with a session unit receives the case-owner fallback. Ended ownership does not confer edit or view access. Admin checks use the session admin role or an ID in the external super-admin configuration. Dev configuration has no super-admin IDs.

Only an admin or configured super-admin may receive real subject identity in Symfony. For all other authorized readers, including the case owner, return the reference label or the numbered fallback when a reference is missing. Filter by accessible cases before searching subject labels. Searching a hidden name must not identify a matching case.

**Legacy discrepancy for cutover review.** The procedural tab-1 edit form renders stored title, first and last name values for an owner who may edit the case. The subject page, read-only views, indexed search and the national-ID prefill use identity checks. The editable name fields conflict with the identity rule above. This observation records the legacy implementation only. Symfony acceptance requires masked owner responses on every tab, including editable forms.
