import { forwardApiDocumentation } from '../../../lib/api-doc-proxy';

export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
  return forwardApiDocumentation('ui');
}
