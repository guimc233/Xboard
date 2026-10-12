import { useEffect, useState } from 'react'
import useSWR from 'swr'
import { LoaderCircle } from 'lucide-react'
import { api, ApiError } from './lib/api'
import { Shell, type PageKey } from './components/Shell'
import { ToastProvider } from './components/ui'
import { LoginPage } from './pages/LoginPage'
import { DashboardPage } from './pages/DashboardPage'
import { NodesPage } from './pages/NodesPage'
import { MachinesPage } from './pages/MachinesPage'
import { GroupsPage } from './pages/GroupsPage'
import { UsersPage } from './pages/UsersPage'
import { RoutingPage } from './pages/RoutingPage'
import { SystemPage } from './pages/SystemPage'

const allowedPages: PageKey[] = ['dashboard', 'nodes', 'machines', 'groups', 'users', 'routing', 'system']

function pageFromHash(): PageKey {
  const value = window.location.hash.replace(/^#\/?/, '').split('/')[0] as PageKey
  return allowedPages.includes(value) ? value : 'dashboard'
}

function CurrentPage({ page, setPage }: { page: PageKey; setPage: (page: PageKey) => void }) {
  if (page === 'nodes') return <NodesPage />
  if (page === 'machines') return <MachinesPage />
  if (page === 'groups') return <GroupsPage />
  if (page === 'users') return <UsersPage />
  if (page === 'routing') return <RoutingPage />
  if (page === 'system') return <SystemPage />
  return <DashboardPage onNavigate={setPage} />
}

export default function App() {
  const demo = import.meta.env.DEV && new URLSearchParams(window.location.search).has('demo')
  const localLogin = import.meta.env.DEV && new URLSearchParams(window.location.search).has('login')
  const auth = useSWR(demo || localLogin ? null : 'admin-auth', api.authCheck, { shouldRetryOnError: false, revalidateOnFocus: false })
  const [page, setPageState] = useState<PageKey>(pageFromHash)

  useEffect(() => {
    const handler = () => setPageState(pageFromHash())
    window.addEventListener('hashchange', handler)
    return () => window.removeEventListener('hashchange', handler)
  }, [])

  function setPage(next: PageKey) {
    window.location.hash = `/${next}`
    setPageState(next)
  }

  async function loggedIn() {
    const result = await auth.mutate()
    if (!result?.is_admin) throw new Error('该账号不是管理员，无法进入管理后台。')
  }

  async function logout() {
    try { await api.logout() } finally {
      await auth.mutate(undefined, { revalidate: false })
      window.location.hash = ''
    }
  }

  if (!demo && !localLogin && !auth.data && !auth.error) {
    return <div className="auth-loading"><div><LoaderCircle className="spin" size={24} /><span>正在验证管理员会话…</span></div></div>
  }

  const unauthenticated = localLogin || (!demo && (!auth.data?.is_login || !auth.data?.is_admin || auth.error instanceof ApiError))
  if (unauthenticated) return <LoginPage onLogin={loggedIn} />

  return (
    <ToastProvider>
      <Shell page={page} setPage={setPage} onLogout={() => void logout()}>
        <CurrentPage page={page} setPage={setPage} />
      </Shell>
    </ToastProvider>
  )
}
