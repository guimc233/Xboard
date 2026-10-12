import { useEffect, useMemo, useState, type ReactNode } from 'react'
import {
  Activity,
  Boxes,
  ChevronRight,
  CircleUserRound,
  Layers,
  LayoutDashboard,
  LogOut,
  Menu,
  Network,
  Route,
  ServerCog,
  Settings2,
  SunMoon,
  UsersRound,
  X,
} from 'lucide-react'
import { Button } from './ui'
import { cn } from '../lib/format'

export type PageKey = 'dashboard' | 'nodes' | 'machines' | 'groups' | 'users' | 'routing' | 'system'

const navigation: Array<{ group: string; items: Array<{ key: PageKey; label: string; icon: typeof Activity }> }> = [
  { group: '概览', items: [{ key: 'dashboard', label: '仪表盘', icon: LayoutDashboard }] },
  {
    group: '基础设施',
    items: [
      { key: 'nodes', label: '节点管理', icon: Network },
      { key: 'machines', label: '服务器管理', icon: ServerCog },
      { key: 'routing', label: '出站与路由', icon: Route },
    ],
  },
  {
    group: '访问控制',
    items: [
      { key: 'groups', label: '权限组', icon: Layers },
      { key: 'users', label: '用户授权', icon: UsersRound },
    ],
  },
  { group: '系统', items: [{ key: 'system', label: '系统状态', icon: Settings2 }] },
]

const pageNames: Record<PageKey, string> = {
  dashboard: '仪表盘', nodes: '节点管理', machines: '服务器管理', groups: '权限组', users: '用户授权', routing: '出站与路由', system: '系统状态',
}

export function Shell({ page, setPage, onLogout, children }: {
  page: PageKey
  setPage: (page: PageKey) => void
  onLogout: () => void
  children: ReactNode
}) {
  const [mobileOpen, setMobileOpen] = useState(false)
  const [dark, setDark] = useState(() => localStorage.getItem('xboard-admin-theme') === 'dark')
  const title = window.settings?.title || 'Xboard'

  useEffect(() => {
    document.documentElement.classList.toggle('dark', dark)
    localStorage.setItem('xboard-admin-theme', dark ? 'dark' : 'light')
  }, [dark])

  const crumbs = useMemo(() => ['管理后台', pageNames[page]], [page])
  const navigate = (key: PageKey) => {
    setPage(key)
    setMobileOpen(false)
  }

  return (
    <div className="app-shell">
      <aside className={cn('sidebar', mobileOpen && 'is-open')}>
        <div className="brand">
          {window.settings?.logo ? <img src={window.settings.logo} alt="" /> : <Boxes aria-hidden="true" />}
          <div><strong>{title}</strong><span>Self-hosted</span></div>
          <Button variant="ghost" size="icon" className="mobile-close" onClick={() => setMobileOpen(false)} aria-label="关闭导航"><X size={19} /></Button>
        </div>
        <nav aria-label="管理后台导航">
          {navigation.map((section) => (
            <div className="nav-section" key={section.group}>
              <div className="nav-label">{section.group}</div>
              {section.items.map((item) => {
                const Icon = item.icon
                return (
                  <button type="button" className={cn('nav-item', page === item.key && 'is-active')} onClick={() => navigate(item.key)} key={item.key}>
                    <Icon size={17} aria-hidden="true" />
                    <span>{item.label}</span>
                    {page === item.key ? <ChevronRight className="nav-arrow" size={15} /> : null}
                  </button>
                )
              })}
            </div>
          ))}
        </nav>
        <div className="sidebar-footer">
          <a href="/" className="nav-item"><CircleUserRound size={17} /><span>用户前台</span></a>
          <button type="button" className="nav-item" onClick={onLogout}><LogOut size={17} /><span>退出登录</span></button>
          <div className="version">Xboard {window.settings?.version || ''}</div>
        </div>
      </aside>
      {mobileOpen ? <button className="sidebar-scrim" type="button" onClick={() => setMobileOpen(false)} aria-label="关闭导航遮罩" /> : null}
      <div className="app-main">
        <header className="topbar">
          <Button variant="ghost" size="icon" className="mobile-menu" onClick={() => setMobileOpen(true)} aria-label="打开导航"><Menu size={20} /></Button>
          <div className="breadcrumbs">
            {crumbs.map((crumb, index) => <span key={crumb}>{index ? <ChevronRight size={14} /> : null}{crumb}</span>)}
          </div>
          <div className="topbar-actions">
            <Button variant="ghost" size="icon" onClick={() => setDark((value) => !value)} aria-label="切换明暗主题"><SunMoon size={18} /></Button>
            <div className="admin-avatar">A</div>
          </div>
        </header>
        <main className="page-content">{children}</main>
      </div>
    </div>
  )
}
