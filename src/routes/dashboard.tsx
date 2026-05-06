import { createFileRoute, Link, Outlet, useNavigate, useRouterState } from "@tanstack/react-router";
import { useEffect, useState } from "react";
import { useAuth } from "@/lib/auth-context";
import {
  LayoutGrid, Bell, Bot, BookOpen, Users, BarChart3, Plug, Settings, MessageSquareHeart,
  ChevronLeft, ChevronRight, LogOut, Loader2,
} from "lucide-react";

export const Route = createFileRoute("/dashboard")({
  component: DashboardLayout,
});

const NAV: { to: any; label: string; icon: any; exact?: boolean }[] = [
  { to: "/dashboard", label: "Overview", icon: LayoutGrid, exact: true },
  { to: "/dashboard/notifications", label: "Notifications", icon: Bell },
  { to: "/dashboard/chatbots", label: "Chatbots", icon: Bot },
  { to: "/dashboard/knowledge", label: "Knowledge", icon: BookOpen },
  { to: "/dashboard/leads", label: "Leads", icon: Users },
  { to: "/dashboard/analytics", label: "Analytics", icon: BarChart3 },
  { to: "/dashboard/integrations", label: "Integrations", icon: Plug },
  { to: "/dashboard/settings", label: "Settings", icon: Settings },
  { to: "/dashboard/feedback", label: "Feedback", icon: MessageSquareHeart },
];

function DashboardLayout() {
  const { user, loading, signOut } = useAuth();
  const navigate = useNavigate();
  const { location } = useRouterState();
  const [collapsed, setCollapsed] = useState(false);

  useEffect(() => {
    if (!loading && !user) navigate({ to: "/auth" });
  }, [user, loading, navigate]);

  if (loading || !user) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-background">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="min-h-screen bg-background flex">
      <aside
        className={`${collapsed ? "w-[72px]" : "w-60"} shrink-0 border-r border-border bg-sidebar flex flex-col transition-[width] duration-200 relative`}
      >
        <div className="flex items-center gap-2 p-5 border-b border-sidebar-border h-[72px]">
          <Link to="/" className="flex items-center gap-2 font-display font-extrabold text-xl">
            <span className="h-8 w-8 rounded-lg bg-gradient-primary shadow-glow flex items-center justify-center">
              <Bot className="h-5 w-5 text-primary-foreground" />
            </span>
            {!collapsed && (
              <span className="bg-gradient-to-r from-primary to-primary-glow bg-clip-text text-transparent tracking-wide">
                Helix
              </span>
            )}
          </Link>
          <button
            onClick={() => setCollapsed((c) => !c)}
            className="absolute -right-3 top-7 h-6 w-6 rounded-full bg-surface border border-border flex items-center justify-center text-muted-foreground hover:text-foreground"
            aria-label="Toggle sidebar"
          >
            {collapsed ? <ChevronRight className="h-3.5 w-3.5" /> : <ChevronLeft className="h-3.5 w-3.5" />}
          </button>
        </div>

        <nav className="flex-1 p-3 space-y-1 overflow-y-auto">
          {NAV.map(({ to, label, icon: Icon, exact }) => {
            const active = exact
              ? location.pathname === to
              : location.pathname === to || location.pathname.startsWith(to + "/");
            return (
              <Link
                key={to}
                to={to}
                className={`flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors ${
                  active
                    ? "bg-sidebar-accent text-sidebar-accent-foreground"
                    : "text-sidebar-foreground/80 hover:bg-sidebar-accent/60 hover:text-sidebar-foreground"
                } ${collapsed ? "justify-center" : ""}`}
                title={collapsed ? label : undefined}
              >
                <Icon className="h-4 w-4 shrink-0" />
                {!collapsed && <span>{label}</span>}
              </Link>
            );
          })}
        </nav>

        <div className="p-3 border-t border-sidebar-border">
          <div className={`flex items-center gap-2 px-2 py-2 ${collapsed ? "justify-center" : ""}`}>
            <div className="h-8 w-8 rounded-full bg-gradient-primary flex items-center justify-center text-xs font-semibold text-primary-foreground shrink-0">
              {user.email?.[0].toUpperCase()}
            </div>
            {!collapsed && (
              <>
                <div className="flex-1 min-w-0 flex items-center gap-2">
                  <span className="text-xs font-medium truncate">Account</span>
                </div>
                <button
                  onClick={() => { signOut(); navigate({ to: "/" }); }}
                  className="p-1.5 rounded-md hover:bg-sidebar-accent text-muted-foreground hover:text-foreground"
                  title="Sign out"
                >
                  <LogOut className="h-4 w-4" />
                </button>
              </>
            )}
          </div>
        </div>
      </aside>

      <main className="flex-1 min-w-0 overflow-auto">
        <Outlet />
      </main>
    </div>
  );
}
