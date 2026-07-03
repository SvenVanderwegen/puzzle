/**
 * The one h1 per page. tabIndex={-1} lets route-change focus management
 * (AppChrome) land on it without adding it to the tab order.
 */
import type { ReactElement, ReactNode } from 'react';

export function PageHeading(props: { readonly children: ReactNode }): ReactElement {
  return (
    <h1 className="bf-page-heading" tabIndex={-1}>
      {props.children}
    </h1>
  );
}
