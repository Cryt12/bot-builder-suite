import helixLogo from "../../helix logo.png";
import { cn } from "@/lib/utils";

type AppLogoProps = {
  className?: string;
  imageClassName?: string;
  textClassName?: string;
  showWordmark?: boolean;
};

export function AppLogo({
  className,
  imageClassName,
  textClassName,
  showWordmark = true,
}: AppLogoProps) {
  return (
    <span className={cn("flex items-center gap-1", className)}>
      <img
        src={helixLogo}
        alt="Helix logo"
        className={cn("h-8 w-8 rounded-lg object-contain", imageClassName)}
      />
      {showWordmark ? (
        <span className={cn("font-display font-bold text-lg", textClassName)}>
          Helix
        </span>
      ) : null}
    </span>
  );
}
